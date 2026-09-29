<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\SyncCloudHubCommand;
use App\Enums\SyncKey;
use App\Http\Controllers\Controller;
use App\Models\GlobalBlacklistOutbox;
use App\Models\SyncStateLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * The sync indicator in the SPA header (hybrid spec §8.3, decisions FE-4c, PE-6): green when both
 * pulls succeeded within five minutes, yellow within thirty, red after that or never; "off" while
 * VMS_SYNC_ENABLED is false. A pull the hub reports as switched off counts as fine.
 */
class SyncController extends Controller
{
    private const GREEN_MINUTES = 5;

    private const YELLOW_MINUTES = 30;

    public function status(): JsonResponse
    {
        $states = SyncStateLog::all()->keyBy(fn (SyncStateLog $state) => $state->sync_key->value);
        $enabled = (bool) config('vms.sync.enabled');

        $ages = [];
        foreach ([SyncKey::BlacklistPull, SyncKey::PreRegistrations] as $key) {
            $state = $states->get($key->value);
            if ($state?->status === 'disabled') {
                continue;
            }
            $success = $state?->last_success_at;
            $ages[] = $success === null ? null : (int) floor($success->diffInMinutes(now(), true));
        }

        $minutes = in_array(null, $ages, true) || $ages === [] ? null : max($ages);
        $level = match (true) {
            ! $enabled => 'off',
            $minutes === null => 'red',
            $minutes <= self::GREEN_MINUTES => 'green',
            $minutes <= self::YELLOW_MINUTES => 'yellow',
            default => 'red',
        };

        return response()->json([
            'enabled' => $enabled,
            'yard_code' => (string) config('vms.sync.yard_code'),
            'level' => $level,
            'minutes_since_sync' => $minutes,
            'pending_outbox' => GlobalBlacklistOutbox::whereNull('sent_at')->count(),
            'channels' => collect(SyncKey::cases())->map(function (SyncKey $key) use ($states): array {
                $state = $states->get($key->value);

                return [
                    'key' => $key->value,
                    'status' => $state?->status,
                    'last_success_at' => $state?->last_success_at?->format('Y-m-d H:i:s'),
                    'last_attempt_at' => $state?->last_attempt_at?->format('Y-m-d H:i:s'),
                    'last_error' => $state?->last_error,
                ];
            })->all(),
        ]);
    }

    /** "Sync sekarang" after a revocation (SEC-7): waits for a running cycle rather than skipping. */
    public function run(): JsonResponse
    {
        Artisan::call(SyncCloudHubCommand::LOCK, ['--force' => true]);

        return $this->status();
    }
}
