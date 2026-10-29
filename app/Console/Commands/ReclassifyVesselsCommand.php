<?php

namespace App\Console\Commands;

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReclassifyVesselsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:reclassify-vessels
                            {--vessel= : Target a specific vessel by name or code}
                            {--pic= : Target every vessel whose PIC matches this name (e.g. --pic="AUDRIE KOSASIH")}
                            {--to= : Force target classification: "tug_boat", "barge", or "repair_vessel"}
                            {--default= : Fallback classification if unrecognized: "tug_boat" or "barge"}
                            {--dry-run : Simulate reclassification without modifying the database}
                            {--consolidate : Automatically run staying vessels consolidation afterwards}';

    /**
     * The console command aliases.
     *
     * @var array<int, string>
     */
    protected $aliases = ['vms:classify-vessels'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reclassify vessels from Repair Vessel to Tugboat or Barge based on their specifications (vessel_type), name patterns, or manual options, and optionally consolidate staying arrivals';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $forceTo = $this->option('to') ? strtolower(trim((string) $this->option('to'))) : null;
        $defaultOption = $this->option('default') ? strtolower(trim((string) $this->option('default'))) : null;
        $targetVessel = $this->option('vessel') ? trim((string) $this->option('vessel')) : null;
        $targetPic = $this->option('pic') ? trim((string) $this->option('pic')) : null;
        $autoConsolidate = (bool) $this->option('consolidate');

        $forcedType = null;
        if ($forceTo !== null) {
            $forcedType = match ($forceTo) {
                'tug', 'tug_boat', 'tugboat', 'tb' => LocationType::TugBoat,
                'barge', 'bg', 'tongkang', 'tk' => LocationType::Barge,
                'repair', 'repair_vessel' => LocationType::RepairVessel,
                default => null,
            };

            if ($forcedType === null) {
                $this->error("Invalid --to value [{$forceTo}]. Allowed values are: 'tug_boat', 'barge', 'repair_vessel'.");

                return self::FAILURE;
            }
        }

        $defaultType = null;
        if ($defaultOption !== null) {
            $defaultType = match ($defaultOption) {
                'tug', 'tug_boat', 'tugboat', 'tb' => LocationType::TugBoat,
                'barge', 'bg', 'tongkang', 'tk' => LocationType::Barge,
                default => null,
            };

            if ($defaultType === null) {
                $this->error("Invalid --default value [{$defaultOption}]. Allowed values are: 'tug_boat', 'barge'.");

                return self::FAILURE;
            }
        } elseif ($targetPic !== null && $forcedType === null) {
            // When targeting vessels by PIC without --to or --default, unrecognized or null vessels fallback to TugBoat
            $defaultType = LocationType::TugBoat;
        }

        $this->info('====================================================');
        $this->info('  VMS Vessel Reclassification (Tugboat & Barge)');
        $this->info('  Mode: '.($dryRun ? 'DRY-RUN (Simulation, database will NOT be modified)' : 'LIVE EXECUTION (Persisting changes)'));
        $this->info('====================================================');

        // Query candidates
        $query = Location::query();

        if ($targetVessel !== null || $targetPic !== null) {
            if ($targetVessel !== null) {
                $lowerTarget = strtolower($targetVessel);
                $query->where(function ($q) use ($lowerTarget) {
                    $q->whereRaw('LOWER(name) LIKE ?', ["%{$lowerTarget}%"])
                        ->orWhereRaw('LOWER(code) LIKE ?', ["%{$lowerTarget}%"]);
                });
            }

            if ($targetPic !== null) {
                // Every vessel under this PIC, whatever its current type; places are never vessels.
                $lowerPic = strtolower($targetPic);
                $query->whereRaw('LOWER(pic) LIKE ?', ["%{$lowerPic}%"])
                    ->where(function ($q) {
                        $q->where('type', '!=', LocationType::Location)
                            ->orWhereNull('type');
                    });
            }
        } else {
            // Default: check vessels currently categorized as repair_vessel or generic
            $query->where(function ($q) {
                $q->where('type', LocationType::RepairVessel)
                    ->orWhereNull('type');
            });
        }

        $candidates = $query->orderBy('name')->get();

        if ($candidates->isEmpty()) {
            $this->info('No matching vessels found to reclassify.');

            return self::SUCCESS;
        }

        $reclassifications = [];

        foreach ($candidates as $loc) {
            $detected = self::detectVesselType($loc);
            $newType = $forcedType ?? $detected ?? $defaultType;

            if ($newType === null) {
                continue;
            }

            $currentType = $loc->type instanceof LocationType ? $loc->type : LocationType::tryFrom((string) $loc->type);

            // Skip if already in the target type
            if ($currentType === $newType) {
                continue;
            }

            if ($forcedType !== null) {
                $reason = "Manual override (--to={$forceTo}".($targetPic !== null ? ", --pic={$targetPic}" : '').')';
            } elseif ($detected !== null) {
                $reason = self::determineReason($loc, $detected);
            } else {
                $specOrName = ! empty($loc->vessel_type) ? "spec: '{$loc->vessel_type}'" : "name: '{$loc->name}'";
                $reason = "Fallback for null/unrecognized detail ({$specOrName})";
            }

            $reclassifications[] = [
                'location' => $loc,
                'old_type' => $currentType ? $currentType->value : 'null',
                'new_type' => $newType,
                'reason' => $reason,
            ];
        }

        if (empty($reclassifications)) {
            $this->info("Scanned {$candidates->count()} vessel(s), but none needed reclassification.");

            return self::SUCCESS;
        }

        $this->info('Found '.count($reclassifications)." vessel(s) to reclassify:\n");

        $tableRows = [];
        foreach ($reclassifications as $item) {
            $loc = $item['location'];
            $tableRows[] = [
                $loc->id,
                $loc->name,
                $loc->code ?? '-',
                $loc->pic ?? '-',
                $loc->vessel_type ?? '-',
                $item['old_type'],
                $item['new_type']->value,
                $item['reason'],
            ];
        }

        $this->table(
            ['ID', 'Name', 'Code', 'PIC', 'Vessel Spec', 'Old Type', 'New Type', 'Reason'],
            $tableRows
        );

        if (! $dryRun) {
            DB::transaction(function () use ($reclassifications) {
                foreach ($reclassifications as $item) {
                    /** @var Location $loc */
                    $loc = $item['location'];
                    /** @var LocationType $newType */
                    $newType = $item['new_type'];

                    $updates = ['type' => $newType];

                    // If vessel_type specification is empty, default it cleanly
                    if (blank($loc->vessel_type) && in_array($newType, [LocationType::TugBoat, LocationType::Barge], true)) {
                        $updates['vessel_type'] = $newType === LocationType::TugBoat ? 'TUG BOAT' : 'BARGE';
                    }

                    $loc->update($updates);
                }
            });

            $this->newLine();
            $this->info('✓ Successfully reclassified '.count($reclassifications).' vessel(s).');
        } else {
            $this->newLine();
            $this->warn('Simulation complete. Run without --dry-run to apply changes.');
        }

        // Trigger staying vessels consolidation if requested
        if ($autoConsolidate) {
            $this->newLine();
            $this->info('Running staying vessels consolidation command...');
            $this->call('vms:consolidate-staying-vessels', [
                '--dry-run' => $dryRun,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Detect whether a location is a Tugboat or Barge based on its fields.
     */
    public static function detectVesselType(Location $location): ?LocationType
    {
        $vesselType = strtoupper(trim((string) $location->vessel_type));
        $name = strtoupper(trim((string) $location->name));
        $code = strtoupper(trim((string) $location->code));

        // 1. Inspect vessel_type specification
        if (! empty($vesselType)) {
            if (
                str_contains($vesselType, 'BARGE')
                || str_contains($vesselType, 'TONGKANG')
                || str_contains($vesselType, 'PONTOON')
                || str_contains($vesselType, 'HOPPER')
                || str_contains($vesselType, 'FLAT TOP')
                || preg_match('/\b(BG|TK)\b/i', $vesselType)
            ) {
                return LocationType::Barge;
            }

            if (
                str_contains($vesselType, 'TUG')
                || str_contains($vesselType, 'TOWING')
                || str_contains($vesselType, 'PUSHER')
                || str_contains($vesselType, 'AHT')
                || str_contains($vesselType, 'ANCHOR BOAT')
                || preg_match('/\b(TB)\b/i', $vesselType)
            ) {
                return LocationType::TugBoat;
            }
        }

        // 2. Inspect name and code patterns for Tugboats
        if (
            preg_match('/^(TB|TUGBOAT|AHT|AHTS)[\.\s\-_]/i', $name)
            || preg_match('/\b(TB|TUGBOAT|TUG\s*BOAT|AHT|AHTS)\b/i', $name)
            || preg_match('/\(EX\.?\s*(TB|TUGBOAT)/i', $name)
            || preg_match('/^(TB|TUGBOAT|AHT)[\.\s\-_]/i', $code)
        ) {
            return LocationType::TugBoat;
        }

        // 3. Inspect name and code patterns for Barges
        if (
            preg_match('/^(BG|TK|CB|OB)[\.\s\-_]/i', $name)
            || preg_match('/\b(BG|BARGE|TONGKANG|PONTOON|HOPPER)\b/i', $name)
            || preg_match('/\(EX\.?\s*(BG|BARGE|TK)/i', $name)
            || preg_match('/^(BG|TK|CB|OB)[\.\s\-_]/i', $code)
            || preg_match('/\bT\s*-?\s*\d{4,6}\b/i', $name) // e.g. CAPITOL T25005, T25005, T-25005
            || preg_match('/\b(ASL|AOM|AB|CB)\s*[-_]?\s*\d{3,4}\b/i', $name) // e.g. ASL 1864, AB-1503
            || preg_match('/\b(TRINITY|BERAU COAL)\s+\d{2,4}\b/i', $name) // e.g. TRINITY 330, BERAU COAL 62
        ) {
            return LocationType::Barge;
        }

        return null;
    }

    /**
     * Provide a human-readable reason why this type was detected.
     */
    protected static function determineReason(Location $location, LocationType $detectedType): string
    {
        $vesselType = strtoupper(trim((string) $location->vessel_type));
        $name = trim((string) $location->name);

        if (! empty($vesselType)) {
            if ($detectedType === LocationType::Barge && (
                str_contains($vesselType, 'BARGE')
                || str_contains($vesselType, 'TONGKANG')
                || str_contains($vesselType, 'PONTOON')
                || str_contains($vesselType, 'HOPPER')
                || preg_match('/\b(BG|TK)\b/i', $vesselType)
            )) {
                return "vessel_type spec ('{$location->vessel_type}')";
            }
            if ($detectedType === LocationType::TugBoat && (
                str_contains($vesselType, 'TUG')
                || str_contains($vesselType, 'TOWING')
                || str_contains($vesselType, 'PUSHER')
                || str_contains($vesselType, 'AHT')
                || preg_match('/\b(TB)\b/i', $vesselType)
            )) {
                return "vessel_type spec ('{$location->vessel_type}')";
            }
        }

        return "Matched name pattern ('{$name}')";
    }
}
