<?php

namespace App\Services\CloudHub;

use App\Enums\PreRegistrationStatus;
use App\Enums\SyncKey;
use App\Models\GlobalBlacklist;
use App\Models\GlobalBlacklistOutbox;
use App\Models\PreRegistration;
use App\Models\SyncStateLog;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One sync cycle with the cloud hub (hybrid spec §5.4). Each channel records its own result in
 * sync_state_logs and a failure in one never stops the next: the gate works without the hub, so a
 * cycle only ever catches up.
 *
 *   1. push the outbox, oldest first, stopping at the first "try again later";
 *   2. mirror global blacklist changes after the saved sync_seq cursor (PE-3);
 *   3. pull this yard's pre-registrations, link walk-ins (PE-4), acknowledge them;
 *   4. expire pending pre-registrations whose day has passed.
 */
class CloudHubSync
{
    private const PUSH_PATH = '/sync/blacklist';

    private const PULL_BLACKLIST_PATH = '/sync/blacklist';

    private const PULL_PRE_REGISTRATIONS_PATH = '/sync/pre-registrations';

    private const ACK_PATH = '/sync/pre-registrations/ack';

    /** Guards against a hub that keeps answering has_more with the same page. */
    private const MAX_PAGES = 200;

    public function __construct(private readonly CloudHubClient $client) {}

    /** @return string|null why the configuration cannot work, or null when it can */
    public static function configurationProblem(): ?string
    {
        $config = config('vms.sync');
        foreach (['yard_code' => 'VMS_YARD_CODE', 'hub_url' => 'VMS_CLOUD_HUB_URL', 'key_id' => 'VMS_CLOUD_HMAC_KEY_ID', 'secret' => 'VMS_CLOUD_HMAC_SECRET'] as $key => $env) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return "{$env} is not set in .env.";
            }
        }

        return null;
    }

    public function run(): void
    {
        $this->channel(SyncKey::BlacklistPush, fn (): int => $this->pushOutbox());
        $this->channel(SyncKey::BlacklistPull, fn (): int|string => $this->pullBlacklist());
        $this->channel(SyncKey::PreRegistrations, fn (): int => $this->pullPreRegistrations());
        PreRegistration::expirePast();
    }

    /** Records a configuration problem on every channel, so the header shows why nothing syncs. */
    public static function recordProblem(string $problem): void
    {
        foreach ([SyncKey::BlacklistPush, SyncKey::BlacklistPull, SyncKey::PreRegistrations] as $key) {
            SyncStateLog::updateOrCreate(['sync_key' => $key->value], ['last_attempt_at' => now(), 'status' => 'error', 'last_error' => $problem]);
        }
    }

    /**
     * Runs one channel and records its outcome. The step returns how many records it handled, or
     * 'disabled' when the hub has that feature switched off.
     *
     * @param  callable(): (int|string)  $step
     */
    private function channel(SyncKey $key, callable $step): void
    {
        $attemptedAt = now();

        try {
            $result = $step();
            $outcome = [
                'status' => $result === 'disabled' ? 'disabled' : 'ok',
                'last_error' => null,
                'last_success_at' => now(),
                'records_synced' => is_int($result) ? $result : 0,
            ];
        } catch (CloudHubUnavailable $e) {
            $outcome = ['status' => 'error', 'last_error' => mb_substr($e->getMessage(), 0, 1000)];
        } catch (Throwable $e) {
            report($e);
            $outcome = ['status' => 'error', 'last_error' => 'Unexpected error: '.class_basename($e)];
        }

        // Read-modify-write after the step: the blacklist pull moves last_seq on this same row.
        SyncStateLog::updateOrCreate(['sync_key' => $key->value], ['last_attempt_at' => $attemptedAt, ...$outcome]);
    }

    private function pushOutbox(): int
    {
        $sent = 0;

        foreach (GlobalBlacklistOutbox::pending()->get() as $row) {
            try {
                $response = $this->client->post(self::PUSH_PATH, array_filter([
                    'action' => $row->action,
                    'id_type' => $row->id_type,
                    'id_number' => $row->id_number,
                    'name' => $row->name,
                    'reason_category' => $row->reason_category?->value,
                    'client_ref' => $row->id,
                ], fn ($value) => $value !== null));
            } catch (CloudHubUnavailable $e) {
                $row->forceFill(['attempts' => $row->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 1000)])->save();
                throw $e;
            }

            // 409 = the number is already global (another yard listed it): nothing left to do here.
            // 403/404/422 = the hub will never accept this request: record why and move on.
            $accepted = $response->successful();
            $row->forceFill([
                'sent_at' => now(),
                'attempts' => $row->attempts + 1,
                'last_error' => $accepted ? null : mb_substr((string) ($response->json('message') ?: "Hub answered {$response->status()}"), 0, 1000),
            ])->save();
            $sent++;
        }

        return $sent;
    }

    private function pullBlacklist(): int|string
    {
        $state = SyncStateLog::find(SyncKey::BlacklistPull->value);
        $cursor = (int) ($state?->last_seq ?? 0);
        $mirrored = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->get(self::PULL_BLACKLIST_PATH, [
                'after_seq' => $cursor,
                'limit' => (int) config('vms.sync.blacklist_batch', 500),
            ]);

            if ($response->status() === 404) {
                return 'disabled';
            }
            if (! $response->successful()) {
                throw new CloudHubUnavailable("Hub answered {$response->status()} to the blacklist pull");
            }

            $entries = (array) $response->json('data', []);
            DB::transaction(function () use ($entries, &$cursor, &$mirrored): void {
                foreach ($entries as $entry) {
                    GlobalBlacklist::updateOrCreate(['hub_id' => $entry['id']], [
                        'id_type' => $entry['id_type'],
                        'id_number' => $entry['id_number'],
                        'name' => $entry['name'],
                        'reason_category' => $entry['reason_category'],
                        'registered_by_yard' => $entry['registered_by_yard'],
                        'is_active' => (bool) $entry['is_active'],
                        'sync_seq' => (int) $entry['sync_seq'],
                    ]);
                    $cursor = max($cursor, (int) $entry['sync_seq']);
                    $mirrored++;
                }

                // The cursor moves in the same transaction as the rows it covers.
                SyncStateLog::updateOrCreate(['sync_key' => SyncKey::BlacklistPull->value], ['last_seq' => $cursor]);
            });

            if (! $response->json('has_more') || $entries === []) {
                break;
            }
        }

        return $mirrored;
    }

    private function pullPreRegistrations(): int
    {
        $pulled = 0;
        $batch = (int) config('vms.sync.pre_registration_batch', 100);

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->client->get(self::PULL_PRE_REGISTRATIONS_PATH, ['limit' => $batch]);
            if (! $response->successful()) {
                throw new CloudHubUnavailable("Hub answered {$response->status()} to the pre-registration pull");
            }

            $rows = (array) $response->json('data', []);
            if ($rows === []) {
                break;
            }

            // Stored first, acknowledged second: an ACK lost on the way only means the hub sends the
            // same rows again, and the upsert by token absorbs them.
            $tokens = DB::transaction(fn (): array => array_map(fn (array $row): string => $this->store($row), $rows));

            $ack = $this->client->post(self::ACK_PATH, ['tokens' => $tokens]);
            if (! $ack->successful()) {
                throw new CloudHubUnavailable("Hub answered {$ack->status()} to the acknowledgement");
            }

            $pulled += count($rows);
            if (count($rows) < $batch) {
                break;
            }
        }

        return $pulled;
    }

    /** @param array<string, mixed> $row */
    private function store(array $row): string
    {
        $preRegistration = PreRegistration::firstOrNew(['token' => PreRegistration::normalizeToken($row['token'])]);

        // A row already checked in or expired here keeps its local state; the hub only resends it
        // because an earlier acknowledgement did not arrive.
        if (! $preRegistration->exists || $preRegistration->status === PreRegistrationStatus::Pending) {
            $preRegistration->fill([
                'id_type' => $row['id_type'],
                'id_number' => $row['id_number'],
                'name' => $row['name'],
                'company' => $row['company'] ?? null,
                'phone' => $row['phone'] ?? null,
                'gender' => $row['gender'] ?? null,
                'purpose' => $row['purpose'] ?? null,
                'host_name' => $row['host_name'] ?? null,
                'host_department' => $row['host_department'] ?? null,
                'scheduled_date' => $row['scheduled_date'],
                'vehicle_no' => $row['vehicle_no'] ?? null,
                'pulled_at' => now(),
            ])->save();

            $preRegistration->linkWalkIn();
        }

        return $preRegistration->token;
    }
}
