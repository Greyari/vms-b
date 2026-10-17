<?php

namespace App\Services;

use App\Models\Blacklist;
use App\Models\Visitor;
use App\Support\NumberingRetry;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BlacklistService
{
    /**
     * Find an existing Visitor matching the provided identity, badge, or name fallback.
     *
     * @param  array{id_type: string, id_number: string, badge_code: ?string, has_real_id: bool}  $parsedIdentity
     */
    public function findMatchingVisitor(
        ?string $visitorId,
        array $parsedIdentity,
        ?string $badgeCode,
        string $name,
        bool $matchByNameWhenNoKtp = false
    ): ?Visitor {
        $visitor = null;

        if (! empty($visitorId)) {
            $visitor = Visitor::find($visitorId);
        }

        if (! $visitor && $parsedIdentity['has_real_id']) {
            $visitor = Visitor::where('id_number', $parsedIdentity['id_number'])->first();
        }

        if (! $visitor && $badgeCode !== null && $badgeCode !== '') {
            $visitor = Visitor::where('badge_code', $badgeCode)->first();
        }

        if (! $visitor && ! $parsedIdentity['has_real_id']) {
            $query = Visitor::where('id_number', $parsedIdentity['id_number']);
            if ($matchByNameWhenNoKtp && $name !== '') {
                $query->orWhereRaw('LOWER(name) = ?', [strtolower($name)]);
            }
            $visitor = $query->first();
        }

        if (! $visitor) {
            $activeBl = $this->findActiveBlacklist(null, $parsedIdentity, $badgeCode, $name, $matchByNameWhenNoKtp);
            if ($activeBl && $activeBl->visitor_id) {
                $visitor = Visitor::find($activeBl->visitor_id);
            }
        }

        return $visitor;
    }

    /**
     * Find an active Blacklist record for the given visitor or identity.
     *
     * @param  array{id_type: string, id_number: string, badge_code: ?string, has_real_id: bool}  $parsedIdentity
     */
    public function findActiveBlacklist(
        ?Visitor $visitor,
        array $parsedIdentity,
        ?string $badgeCode,
        string $name = '',
        bool $matchByNameWhenNoKtp = false
    ): ?Blacklist {
        if ($visitor) {
            $byVisitor = Blacklist::where('visitor_id', $visitor->id)
                ->where('is_active', true)
                ->orderByDesc('blacklisted_at')
                ->orderByDesc('created_at')
                ->first();
            if ($byVisitor) {
                return $byVisitor;
            }
        }

        if ($parsedIdentity['has_real_id']) {
            $byId = Blacklist::where('id_number', $parsedIdentity['id_number'])
                ->where('is_active', true)
                ->orderByDesc('blacklisted_at')
                ->orderByDesc('created_at')
                ->first();
            if ($byId) {
                return $byId;
            }
        }

        if ($badgeCode !== null && $badgeCode !== '') {
            $byBadge = Blacklist::where('badge_code', $badgeCode)
                ->where('is_active', true)
                ->orderByDesc('blacklisted_at')
                ->orderByDesc('created_at')
                ->first();
            if ($byBadge) {
                return $byBadge;
            }
        }

        if (! $parsedIdentity['has_real_id']) {
            $query = Blacklist::where('is_active', true)
                ->where(function ($q) use ($parsedIdentity, $name, $matchByNameWhenNoKtp) {
                    $q->where('id_number', $parsedIdentity['id_number']);
                    if ($matchByNameWhenNoKtp && $name !== '') {
                        $q->orWhereRaw('LOWER(name) = ?', [strtolower($name)]);
                    }
                });

            return $query->orderByDesc('blacklisted_at')->orderByDesc('created_at')->first();
        }

        return null;
    }

    /**
     * Normalize and parse raw blacklist input fields using the shared parser.
     *
     * @param  array<string, mixed>  $input
     * @return array{
     *   visitor_id: ?string,
     *   name: string,
     *   id_type: string,
     *   id_number: string,
     *   badge_code: ?string,
     *   offences: string,
     *   date: string,
     *   remark: ?string,
     *   company: ?string,
     *   parsed_identity: array{id_type: string, id_number: string, badge_code: ?string, has_real_id: bool}
     * }
     */
    public function normalizePayload(array $input): array
    {
        $visitorId = ! empty($input['visitor_id']) ? (string) $input['visitor_id'] : null;
        $name = trim((string) ($input['name'] ?? ''));
        $rawKtp = ! empty($input['id_number']) ? trim((string) $input['id_number']) : '';
        $badgeCode = isset($input['badge_code']) && trim((string) $input['badge_code']) !== ''
            ? trim((string) $input['badge_code'])
            : null;
        $date = MasterImportService::parseDate($input['date'] ?? null) ?? Carbon::now()->format('Y-m-d');
        $remark = isset($input['remark']) && trim((string) $input['remark']) !== ''
            ? trim((string) $input['remark'])
            : null;
        $company = isset($input['company']) && trim((string) $input['company']) !== ''
            ? trim((string) $input['company'])
            : null;
        $offences = trim((string) ($input['offences'] ?? ''));

        $parsedIdentity = MasterImportService::parseIdentity($rawKtp, $badgeCode, $name);
        $idType = $parsedIdentity['id_type'];
        $idNumber = $parsedIdentity['id_number'];
        if ($badgeCode === null && ! empty($parsedIdentity['badge_code'])) {
            $badgeCode = $parsedIdentity['badge_code'];
        }

        return [
            'visitor_id' => $visitorId,
            'name' => $name,
            'id_type' => $idType,
            'id_number' => $idNumber,
            'badge_code' => $badgeCode,
            'offences' => $offences,
            'date' => $date,
            'remark' => $remark,
            'company' => $company,
            'parsed_identity' => $parsedIdentity,
        ];
    }

    /**
     * Create or update a blacklist entry and synchronize the Visitor table atomically.
     *
     * Shared by manual creation (duplicateAction = 'reject'), quick visitor blacklisting
     * (duplicateAction = 'replace'), and Excel/CSV import (duplicateAction = 'update'|'replace'|'skip').
     *
     * @param  array<string, mixed>  $input
     * @return array{action: string, visitor: Visitor, blacklist: Blacklist}
     */
    public function saveEntry(
        array $input,
        string $duplicateAction = 'reject',
        bool $matchByNameWhenNoKtp = false
    ): array {
        $normalized = $this->normalizePayload($input);

        $callback = function () use ($normalized, $duplicateAction, $matchByNameWhenNoKtp): array {
            $visitorId = $normalized['visitor_id'];
            $name = $normalized['name'];
            $idType = $normalized['id_type'];
            $idNumber = $normalized['id_number'];
            $badgeCode = $normalized['badge_code'];
            $offences = $normalized['offences'];
            $date = $normalized['date'];
            $remark = $normalized['remark'];
            $company = $normalized['company'];
            $parsedIdentity = $normalized['parsed_identity'];

            $visitor = $this->findMatchingVisitor($visitorId, $parsedIdentity, $badgeCode, $name, $matchByNameWhenNoKtp);
            $activeBlacklist = $this->findActiveBlacklist($visitor, $parsedIdentity, $badgeCode, $name, $matchByNameWhenNoKtp);

            if ($activeBlacklist && ! $visitor && $activeBlacklist->visitor_id) {
                $visitor = Visitor::find($activeBlacklist->visitor_id);
            }

            $isAlreadyBlacklisted = ($visitor && $visitor->is_blacklisted) || $activeBlacklist !== null;

            // Case 1: Already actively blacklisted -> handle according to duplicateAction
            if ($isAlreadyBlacklisted) {
                if ($duplicateAction === 'reject') {
                    throw ValidationException::withMessages([
                        'id_number' => ['Visitor ini sudah berstatus blacklist aktif.'],
                    ]);
                }

                if ($duplicateAction === 'skip') {
                    if ($visitor && ! $activeBlacklist) {
                        $activeBlacklist = Blacklist::create([
                            'visitor_id' => $visitor->id,
                            'name' => $visitor->name,
                            'id_number' => $visitor->id_number,
                            'badge_code' => $visitor->badge_code,
                            'offences' => $visitor->offences ?: ($visitor->blacklist_reason ?: $offences),
                            'blacklisted_at' => $visitor->blacklisted_at?->format('Y-m-d') ?? $date,
                            'remark' => $visitor->remark,
                            'company' => $visitor->company,
                            'is_active' => true,
                        ]);
                    }

                    return [
                        'action' => 'skipped',
                        'visitor' => $visitor,
                        'blacklist' => $activeBlacklist,
                    ];
                }

                if (! $visitor) {
                    $visitor = new Visitor;
                    $visitor->forceFill([
                        'id_type' => $idType,
                        'id_number' => $idNumber,
                        'name' => $name,
                        'badge_code' => $badgeCode,
                        'offences' => $offences,
                        'blacklist_reason' => $offences,
                        'blacklisted_at' => $date,
                        'remark' => $remark,
                        'company' => $company,
                        'is_blacklisted' => true,
                    ])->save();
                } elseif ($duplicateAction === 'replace') {
                    $visitorPayload = [
                        'name' => $name,
                        'id_type' => $idType,
                        'id_number' => $idNumber,
                        'badge_code' => $badgeCode,
                        'offences' => $offences,
                        'blacklist_reason' => $offences,
                        'blacklisted_at' => $date,
                        'remark' => $remark,
                        'company' => $company,
                        'is_blacklisted' => true,
                    ];

                    // Never overwrite an existing real KTP with a NOKTP placeholder
                    if (! $parsedIdentity['has_real_id'] && ! empty($visitor->id_number)) {
                        unset($visitorPayload['id_type'], $visitorPayload['id_number']);
                    }

                    $visitor->forceFill($visitorPayload)->save();
                } else {
                    // 'update' mode: only overwrite non-empty fields
                    $visitor->name = $name;
                    if ($parsedIdentity['has_real_id'] || empty($visitor->id_number)) {
                        $visitor->id_type = $idType;
                        $visitor->id_number = $idNumber;
                    }
                    if ($badgeCode !== null) {
                        $visitor->badge_code = $badgeCode;
                    }
                    if ($offences !== '') {
                        $visitor->offences = $offences;
                        $visitor->blacklist_reason = $offences;
                    }
                    if ($date !== '') {
                        $visitor->blacklisted_at = $date;
                    }
                    if ($remark !== null) {
                        $visitor->remark = $remark;
                    }
                    if ($company !== null) {
                        $visitor->company = $company;
                    }
                    $visitor->is_blacklisted = true;
                    $visitor->save();
                }

                $blacklistPayload = [
                    'visitor_id' => $visitor->id,
                    'name' => $visitor->name,
                    'id_number' => $visitor->id_number,
                    'badge_code' => $duplicateAction === 'replace'
                        ? $badgeCode
                        : ($badgeCode ?? $activeBlacklist?->badge_code ?? $visitor->badge_code),
                    'offences' => $offences !== '' ? $offences : ($activeBlacklist?->offences ?: $visitor->offences),
                    'blacklisted_at' => $date,
                    'remark' => $duplicateAction === 'replace'
                        ? $remark
                        : ($remark ?? $activeBlacklist?->remark ?? $visitor->remark),
                    'company' => $duplicateAction === 'replace'
                        ? $company
                        : ($company ?? $activeBlacklist?->company ?? $visitor->company),
                    'is_active' => true,
                    'unblacklisted_at' => null,
                ];

                if ($activeBlacklist) {
                    $activeBlacklist->update($blacklistPayload);
                } else {
                    $activeBlacklist = Blacklist::create($blacklistPayload);
                }

                return [
                    'action' => $duplicateAction === 'replace' ? 'replaced' : 'updated',
                    'visitor' => $visitor,
                    'blacklist' => $activeBlacklist,
                ];
            }

            // Case 2: Not currently blacklisted -> create or update Visitor, then create active Blacklist row
            if ($visitor) {
                $visitorPayload = [
                    'name' => $name !== '' ? $name : $visitor->name,
                    'is_blacklisted' => true,
                    'blacklist_reason' => $offences,
                    'offences' => $offences,
                    'badge_code' => $badgeCode ?: $visitor->badge_code,
                    'blacklisted_at' => $date,
                    'remark' => $remark ?: $visitor->remark,
                    'company' => $company ?: $visitor->company,
                ];

                if ($parsedIdentity['has_real_id'] || empty($visitor->id_number)) {
                    $visitorPayload['id_type'] = $idType;
                    $visitorPayload['id_number'] = $idNumber;
                }

                $visitor->forceFill($visitorPayload)->save();
            } else {
                $createVisitor = function () use ($name, $idType, $idNumber, $badgeCode, $offences, $date, $remark, $company): Visitor {
                    $newVisitor = new Visitor;
                    $newVisitor->forceFill([
                        'id_type' => $idType,
                        'id_number' => $idNumber,
                        'name' => $name,
                        'badge_code' => $badgeCode,
                        'offences' => $offences,
                        'blacklist_reason' => $offences,
                        'blacklisted_at' => $date,
                        'remark' => $remark,
                        'company' => $company,
                        'is_blacklisted' => true,
                    ]);
                    $newVisitor->save();

                    return $newVisitor;
                };

                $visitor = DB::transactionLevel() > 0
                    ? $createVisitor()
                    : NumberingRetry::run($createVisitor);
            }

            $blacklist = Blacklist::create([
                'visitor_id' => $visitor->id,
                'name' => $visitor->name,
                'id_number' => $visitor->id_number,
                'badge_code' => $badgeCode ?: $visitor->badge_code,
                'offences' => $offences,
                'blacklisted_at' => $date,
                'remark' => $remark ?? $visitor->remark,
                'company' => $company ?: $visitor->company,
                'is_active' => true,
                'unblacklisted_at' => null,
            ]);

            return [
                'action' => 'created',
                'visitor' => $visitor,
                'blacklist' => $blacklist,
            ];
        };

        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return NumberingRetry::run($callback);
    }

    /**
     * Update an existing blacklist record and keep its linked Visitor synchronized.
     *
     * @param  array<string, mixed>  $input
     * @return array{visitor: ?Visitor, blacklist: Blacklist}
     */
    public function updateEntry(string $id, array $input): array
    {
        $normalized = $this->normalizePayload($input);

        return DB::transaction(function () use ($id, $normalized): array {
            $name = $normalized['name'];
            $idType = $normalized['id_type'];
            $idNumber = $normalized['id_number'];
            $badgeCode = $normalized['badge_code'];
            $offences = $normalized['offences'];
            $date = $normalized['date'];
            $remark = $normalized['remark'];
            $company = $normalized['company'];
            $parsedIdentity = $normalized['parsed_identity'];

            $blacklist = Blacklist::find($id);
            if (! $blacklist) {
                $blacklist = Blacklist::where('visitor_id', $id)->latest()->first();
            }

            $visitorId = $normalized['visitor_id'] ?? $blacklist?->visitor_id ?? $id;
            $visitor = Visitor::find($visitorId);

            if ($visitor) {
                $visitorPayload = [
                    'name' => $name,
                    'id_type' => $idType,
                    'id_number' => $idNumber,
                    'badge_code' => $badgeCode,
                    'offences' => $offences,
                    'blacklist_reason' => $offences,
                    'blacklisted_at' => $date,
                    'remark' => $remark,
                    'company' => $company ?: $visitor->company,
                    'is_blacklisted' => true,
                ];

                if (! $parsedIdentity['has_real_id'] && ! empty($visitor->id_number) && ! str_starts_with((string) $visitor->id_number, 'NOKTP')) {
                    unset($visitorPayload['id_type'], $visitorPayload['id_number']);
                }

                $visitor->forceFill($visitorPayload)->save();
            }

            $blacklistData = [
                'visitor_id' => $visitor?->id,
                'name' => $name,
                'id_number' => $visitor?->id_number ?? $idNumber,
                'badge_code' => $badgeCode,
                'offences' => $offences,
                'blacklisted_at' => $date,
                'remark' => $remark,
                'company' => $company ?: $visitor?->company,
                'is_active' => true,
                'unblacklisted_at' => null,
            ];

            if ($blacklist) {
                $blacklist->update($blacklistData);
            } else {
                $blacklist = Blacklist::create($blacklistData);
            }

            return [
                'visitor' => $visitor,
                'blacklist' => $blacklist,
            ];
        });
    }

    /**
     * Connect an existing Blacklist record to a Visitor record and synchronize both.
     *
     * @return array{visitor: Visitor, blacklist: Blacklist}
     */
    public function connectVisitor(string $blacklistId, string $visitorId): array
    {
        return DB::transaction(function () use ($blacklistId, $visitorId): array {
            $blacklist = Blacklist::findOrFail($blacklistId);
            $visitor = Visitor::findOrFail($visitorId);

            $blacklist->update([
                'visitor_id' => $visitor->id,
                'name' => $visitor->name,
                'id_number' => $visitor->id_number,
                'company' => $visitor->company ?: $blacklist->company,
            ]);

            if ($blacklist->is_active) {
                $visitor->forceFill([
                    'is_blacklisted' => true,
                    'blacklist_reason' => $blacklist->offences,
                    'offences' => $blacklist->offences,
                    'blacklisted_at' => $blacklist->blacklisted_at,
                    'badge_code' => $blacklist->badge_code ?: $visitor->badge_code,
                    'remark' => $blacklist->remark ?: $visitor->remark,
                ])->save();
            }

            return [
                'visitor' => $visitor,
                'blacklist' => $blacklist,
            ];
        });
    }

    /**
     * Revoke active blacklist status by either Blacklist ID or Visitor ID and synchronize both tables.
     */
    public function revoke(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $blacklist = Blacklist::find($id);
            $visitor = null;

            if ($blacklist) {
                $blacklist->update([
                    'is_active' => false,
                    'unblacklisted_at' => now(),
                ]);

                if ($blacklist->visitor_id) {
                    $visitor = Visitor::find($blacklist->visitor_id);
                }
            } else {
                $visitor = Visitor::findOrFail($id);
            }

            if ($visitor) {
                // Deactivate any remaining active blacklist rows for this visitor via model instances for ActivityLog
                $activeRows = Blacklist::where('visitor_id', $visitor->id)
                    ->where('is_active', true)
                    ->get();

                foreach ($activeRows as $row) {
                    $row->update([
                        'is_active' => false,
                        'unblacklisted_at' => now(),
                    ]);
                }

                $visitor->forceFill([
                    'is_blacklisted' => false,
                    'blacklist_reason' => null,
                ])->save();
            }
        });
    }

    /**
     * One-off migration/repair logic: find all Visitors with is_blacklisted = true
     * that do not have an active record in the blacklists table, create their
     * corresponding active Blacklist records without overwriting existing valid
     * data, and return a detailed report.
     *
     * @return array{
     *   repaired_count: int,
     *   already_synced_count: int,
     *   complete_count: int,
     *   needs_manual_completion_count: int,
     *   missing_real_ktp_count: int,
     *   missing_both_ktp_and_badge_count: int,
     *   missing_company_count: int,
     *   missing_remark_count: int,
     *   visitor_flags_repaired_count: int
     * }
     */
    public function repairOrphanedBlacklists(bool $logActivity = true): array
    {
        return DB::transaction(function () use ($logActivity): array {
            $alreadySyncedCount = Visitor::where('is_blacklisted', true)
                ->whereHas('blacklists', fn ($q) => $q->where('is_active', true))
                ->count();

            $orphanedVisitors = Visitor::where('is_blacklisted', true)
                ->whereDoesntHave('blacklists', fn ($q) => $q->where('is_active', true))
                ->orderBy('created_at')
                ->get();

            $repairedCount = 0;
            $completeCount = 0;
            $needsManualCompletionCount = 0;
            $missingRealKtpCount = 0;
            $missingBothKtpAndBadgeCount = 0;
            $missingCompanyCount = 0;
            $missingRemarkCount = 0;

            foreach ($orphanedVisitors as $visitor) {
                $idNumber = (string) ($visitor->id_number ?? '');
                $hasRealKtp = $idNumber !== '' && ! str_starts_with($idNumber, 'NOKTP');
                $badgeCode = $visitor->badge_code !== null && trim((string) $visitor->badge_code) !== ''
                    ? trim((string) $visitor->badge_code)
                    : null;
                $company = $visitor->company !== null && trim((string) $visitor->company) !== ''
                    ? trim((string) $visitor->company)
                    : null;
                $remark = $visitor->remark !== null && trim((string) $visitor->remark) !== ''
                    ? trim((string) $visitor->remark)
                    : null;
                $offences = trim((string) ($visitor->offences ?: ($visitor->blacklist_reason ?: 'Blacklisted by Security / Management')));
                $date = $visitor->blacklisted_at?->format('Y-m-d')
                    ?? $visitor->created_at?->format('Y-m-d')
                    ?? Carbon::now()->format('Y-m-d');

                if (! $hasRealKtp) {
                    $missingRealKtpCount++;
                    if ($badgeCode === null) {
                        $missingBothKtpAndBadgeCount++;
                    }
                }
                if ($company === null) {
                    $missingCompanyCount++;
                }
                if ($remark === null) {
                    $missingRemarkCount++;
                }

                // A record needs manual attention if it lacks both real KTP and badge code, or lacks company
                if ((! $hasRealKtp && $badgeCode === null) || $company === null) {
                    $needsManualCompletionCount++;
                } else {
                    $completeCount++;
                }

                // Insert using saveQuietly() with explicit UUID so HasUuids is populated
                // without flooding 886 individual model-created rows into ActivityLog;
                // a single summary ActivityLog event is recorded for the migration run below.
                $blacklist = new Blacklist([
                    'visitor_id' => $visitor->id,
                    'name' => $visitor->name ?: 'Unknown Offender',
                    'id_number' => $idNumber !== '' ? $idNumber : null,
                    'badge_code' => $badgeCode,
                    'offences' => $offences,
                    'blacklisted_at' => $date,
                    'remark' => $remark,
                    'company' => $company,
                    'is_active' => true,
                    'unblacklisted_at' => null,
                ]);
                $blacklist->id = Str::uuid7()->toString();
                $blacklist->saveQuietly();

                $repairedCount++;
            }

            // Also ensure any active Blacklist record with a linked Visitor has visitor.is_blacklisted = true
            $visitorFlagsRepairedCount = 0;
            $unsyncedActiveBlacklists = Blacklist::where('is_active', true)
                ->whereNotNull('visitor_id')
                ->whereHas('visitor', fn ($q) => $q->where('is_blacklisted', false))
                ->with('visitor')
                ->get();

            foreach ($unsyncedActiveBlacklists as $activeBl) {
                if ($activeBl->visitor) {
                    $activeBl->visitor->forceFill([
                        'is_blacklisted' => true,
                        'blacklist_reason' => $activeBl->offences,
                        'offences' => $activeBl->offences,
                        'blacklisted_at' => $activeBl->blacklisted_at,
                    ])->saveQuietly();
                    $visitorFlagsRepairedCount++;
                }
            }

            $report = [
                'origin' => 'legacy_import_backfill',
                'repaired_count' => $repairedCount,
                'already_synced_count' => $alreadySyncedCount,
                'complete_count' => $completeCount,
                'needs_manual_completion_count' => $needsManualCompletionCount,
                'missing_real_ktp_count' => $missingRealKtpCount,
                'missing_both_ktp_and_badge_count' => $missingBothKtpAndBadgeCount,
                'missing_company_count' => $missingCompanyCount,
                'missing_remark_count' => $missingRemarkCount,
                'visitor_flags_repaired_count' => $visitorFlagsRepairedCount,
            ];

            if ($logActivity && ($repairedCount > 0 || $visitorFlagsRepairedCount > 0)) {
                ActivityLogger::logEvent('migrate_orphaned_blacklists', $report);
            }

            return $report;
        });
    }
}
