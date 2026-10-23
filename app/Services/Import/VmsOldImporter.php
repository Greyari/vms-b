<?php

namespace App\Services\Import;

use App\Models\Location;
use App\Models\Nationality;
use App\Models\Staff;
use App\Models\VesselArrival;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Services\Import\Transformers\CardTransformer;
use App\Services\Import\Transformers\LocationTransformer;
use App\Services\Import\Transformers\NationalityTransformer;
use App\Services\Import\Transformers\StaffTransformer;
use App\Services\Import\Transformers\VesselArrivalTransformer;
use App\Services\Import\Transformers\VisitorTransformer;
use App\Services\Import\Transformers\VisitTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class VmsOldImporter
{
    /** @var array<string, string> */
    protected array $nationalityMap = [];

    /** @var array<string, string> */
    protected array $locationMap = [];

    /** @var array<string, string> */
    protected array $locationNameMap = [];

    /** @var array<string, string> */
    protected array $staffMap = [];

    /** @var array<string, string> */
    protected array $cardMap = [];

    protected bool $skipCards = false;

    public function setSkipCards(bool $skip): self
    {
        $this->skipCards = $skip;

        return $this;
    }

    public function __construct(
        protected MappingManager $mappingManager,
        protected ImportReport $report
    ) {}

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    protected function readCsv(string $filePath): \Generator
    {
        if (! File::exists($filePath)) {
            return;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return;
        }

        // Ekspor PowerShell menulis UTF-8 dengan BOM; lewati BOM sebelum parsing agar tanda kutip header terbaca.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return;
        }

        $headers = array_map('trim', $headers);

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === 1 && $row[0] === null) {
                continue;
            }
            $data = [];
            foreach ($headers as $index => $header) {
                $data[$header] = $row[$index] ?? null;
            }
            yield $data;
        }

        fclose($handle);
    }

    public function import(string $path, bool $dryRun = false): void
    {
        Model::withoutEvents(function () use ($path, $dryRun) {
            $this->importNationalities($path, $dryRun);
            $this->importLocations($path, $dryRun);
            $this->importStaff($path, $dryRun);
            if (! $this->skipCards) {
                $this->importCards($path, $dryRun);
            }
            $this->importVisitors($path, $dryRun);
            $this->importVisits($path, $dryRun);
            $this->importVesselArrivals($path, $dryRun);
        });
    }

    protected function importNationalities(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'Nationality.csv';
        $transformer = new NationalityTransformer;

        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('nationalities');
            $transformed = $transformer->transform($row);

            if (! $transformed) {
                $this->report->incrementSkipped('nationalities', 'empty_description');

                continue;
            }

            $sourceKey = $transformed['source_key'];
            $targetId = $this->mappingManager->getTargetId('vms-old', 'nationalities', $sourceKey);

            if ($targetId) {
                if (! $dryRun) {
                    $nat = Nationality::find($targetId);
                    if ($nat) {
                        $nat->update($transformed['attributes']);
                    }
                }
                $this->report->incrementUpdated('nationalities');
            } else {
                if (! $dryRun) {
                    // Nama kebangsaan unik: pakai yang sudah ada (mis. dua kode lama untuk "Samoa").
                    $nat = Nationality::query()
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower($transformed['attributes']['name'])])
                        ->first();

                    if (! $nat) {
                        $nat = new Nationality($transformed['attributes']);
                        $nat->id = (string) Str::uuid7();
                        $nat->save();
                    }

                    $targetId = $nat->id;
                    $this->mappingManager->put('vms-old', 'nationalities', $sourceKey, $targetId);
                } else {
                    $targetId = 'dry-run-nat-'.$sourceKey;
                }
                $this->report->incrementInserted('nationalities');
            }

            $this->nationalityMap[$sourceKey] = $targetId;
        }
    }

    protected function importLocations(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'ESSVesselMaster.csv';
        $transformer = new LocationTransformer;

        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('locations');
            $transformed = $transformer->transform($row);

            if (! $transformed) {
                $this->report->incrementSkipped('locations', 'empty_name');

                continue;
            }

            $sourceKey = $transformed['source_key'];
            $targetId = $this->mappingManager->getTargetId('vms-old', 'locations', $sourceKey);

            if ($targetId) {
                if (! $dryRun) {
                    $loc = Location::find($targetId);
                    if ($loc) {
                        $loc->update($transformed['attributes']);
                    }
                }
                $this->report->incrementUpdated('locations');
            } else {
                if (! $dryRun) {
                    // Kode unik: jika sudah ada di VMS baru, tautkan saja tanpa menimpa.
                    $loc = Location::query()
                        ->whereRaw('LOWER(code) = ?', [mb_strtolower($transformed['attributes']['code'])])
                        ->first();
                    if (! $loc) {
                        $loc = new Location($transformed['attributes']);
                        $loc->id = (string) Str::uuid7();
                        $loc->save();
                    }
                    $targetId = $loc->id;
                    $this->mappingManager->put('vms-old', 'locations', $sourceKey, $targetId);
                } else {
                    $targetId = 'dry-run-loc-'.$sourceKey;
                }
                $this->report->incrementInserted('locations');
            }

            $this->locationMap[$sourceKey] = $targetId;
            $this->locationNameMap[$sourceKey] = $transformed['attributes']['name'];
        }
    }

    protected function importStaff(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'ESSStaffToVisit.csv';
        $transformer = new StaffTransformer;

        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('staff');
            $transformed = $transformer->transform($row);

            if (! $transformed) {
                $this->report->incrementSkipped('staff', 'missing_staff_id');

                continue;
            }

            $sourceKey = $transformed['source_key'];
            $targetId = $this->mappingManager->getTargetId('vms-old', 'staff', $sourceKey);

            if ($targetId) {
                if (! $dryRun) {
                    $staff = Staff::find($targetId);
                    if ($staff) {
                        $staff->update($transformed['attributes']);
                    }
                }
                $this->report->incrementUpdated('staff');
            } else {
                if (! $dryRun) {
                    $staff = Staff::query()->where('staff_id', $transformed['attributes']['staff_id'] ?? null)->first();
                    if (! $staff) {
                        $staff = new Staff($transformed['attributes']);
                        $staff->id = (string) Str::uuid7();
                        $staff->save();
                    }
                    $targetId = $staff->id;
                    $this->mappingManager->put('vms-old', 'staff', $sourceKey, $targetId);
                } else {
                    $targetId = 'dry-run-staff-'.$sourceKey;
                }
                $this->report->incrementInserted('staff');
            }

            $this->staffMap[$sourceKey] = $targetId;
        }
    }

    protected function importCards(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'ESSVisitorCardMaster.csv';
        $transformer = new CardTransformer;

        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('cards');
            $transformed = $transformer->transform($row);

            if (! $transformed) {
                $this->report->incrementSkipped('cards', 'empty_card_no');

                continue;
            }

            $sourceKey = $transformed['source_key'];
            $targetId = $this->mappingManager->getTargetId('vms-old', 'cards', $sourceKey);

            if ($targetId) {
                if (! $dryRun) {
                    $card = VisitorCard::find($targetId);
                    if ($card) {
                        $card->update($transformed['attributes']);
                    }
                }
                $this->report->incrementUpdated('cards');
            } else {
                if (! $dryRun) {
                    $card = VisitorCard::query()->where('card_no', $transformed['attributes']['card_no'] ?? null)->first();
                    if (! $card) {
                        $card = new VisitorCard($transformed['attributes']);
                        $card->id = (string) Str::uuid7();
                        $card->save();
                    }
                    $targetId = $card->id;
                    $this->mappingManager->put('vms-old', 'cards', $sourceKey, $targetId);
                } else {
                    $targetId = 'dry-run-card-'.$sourceKey;
                }
                $this->report->incrementInserted('cards');
            }

            $this->cardMap[$sourceKey] = $targetId;
        }
    }

    protected function importVisitors(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'ESSVisitorMaster.csv';
        $transformer = new VisitorTransformer;

        $rows = [];
        foreach ($this->readCsv($filePath) as $row) {
            $rows[] = $row;
        }

        $canonicalGroups = $transformer->groupCanonical($rows, $this->locationMap);

        foreach ($canonicalGroups as $group) {
            $canonicalId = $group['canonical_id'];
            $sourceKeys = $group['member_visitor_ids'];
            $this->report->incrementRead('visitors', count($sourceKeys));

            $targetId = $this->mappingManager->getTargetId('vms-old', 'visitors', $canonicalId);

            if ($targetId) {
                if (! $dryRun) {
                    $vis = Visitor::find($targetId);
                    if ($vis) {
                        $vis->fill($group['attributes']);
                        $vis->save();
                    }
                }
                $this->report->incrementUpdated('visitors');
            } else {
                if (! $dryRun) {
                    // Sudah ada di VMS baru (id_type + id_number sama)? Tautkan saja tanpa menimpa data,
                    // hanya isi kapal bila masih kosong.
                    $existing = ($group['attributes']['id_number'] ?? '') !== ''
                        ? Visitor::query()
                            ->where('id_type', $group['attributes']['id_type'])
                            ->where('id_number', $group['attributes']['id_number'])
                            ->first()
                        : null;

                    if ($existing) {
                        if ($existing->vessel_id === null && ! empty($group['attributes']['vessel_id'])) {
                            $existing->vessel_id = $group['attributes']['vessel_id'];
                            $existing->save();
                        }
                        $vis = $existing;
                    } else {
                        $vis = new Visitor($group['attributes']);
                        $vis->id = (string) Str::uuid7();
                        // Pertahankan nomor lama; jika sudah dipakai visitor lain, biarkan model memberi nomor baru.
                        if (! Visitor::query()->where('visitor_no', (int) $canonicalId)->exists()) {
                            $vis->visitor_no = (int) $canonicalId;
                        }
                        $vis->save();
                    }
                    $targetId = $vis->id;
                } else {
                    $targetId = 'dry-run-vis-'.$canonicalId;
                }
                $this->report->incrementInserted('visitors');
            }

            if (! $dryRun && $targetId) {
                foreach ($sourceKeys as $memberKey) {
                    $this->mappingManager->put('vms-old', 'visitors', (string) $memberKey, $targetId);
                }
            }

            if ($group['is_duplicate']) {
                $this->report->incrementSkipped('visitors', 'duplicate_canonical', count($sourceKeys) - 1);
            }
        }
    }

    protected function importVisits(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'ESSVisitorRegistration.csv';
        $transformer = new VisitTransformer;

        // Load visitor map
        $visitorMap = $this->mappingManager->loadEntityMap('vms-old', 'visitors');

        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('visits');

            $transformed = $transformer->transform(
                $row,
                $visitorMap,
                $this->staffMap,
                $this->locationMap,
                $this->cardMap
            );

            if (! $transformed) {
                $this->report->incrementSkipped('visits', 'missing_visitor_master');

                continue;
            }

            $sourceKey = $transformed['source_key'];
            $cardId = $this->skipCards ? null : $transformed['card_id'];

            if (! $this->skipCards && $transformed['is_orphan_card'] && ! empty($transformed['raw_card_no'])) {
                $rawCardNo = $transformed['raw_card_no'];
                if (! isset($this->cardMap[$rawCardNo])) {
                    if (! $dryRun) {
                        $orphanCard = new VisitorCard([
                            'card_no' => $rawCardNo,
                            'status' => 'available',
                            'is_active' => false,
                            'note' => 'Dibuat dari data kunjungan VMS-old',
                        ]);
                        $orphanCard->id = (string) Str::uuid7();
                        $orphanCard->save();
                        $this->cardMap[$rawCardNo] = $orphanCard->id;
                        $this->mappingManager->put('vms-old', 'cards', $rawCardNo, $orphanCard->id);
                    } else {
                        $this->cardMap[$rawCardNo] = 'dry-run-orphan-card';
                    }
                }
                $cardId = $this->cardMap[$rawCardNo];
            }

            $targetVisitId = $this->mappingManager->getTargetId('vms-old', 'visits', $sourceKey);

            if ($targetVisitId) {
                if (! $dryRun) {
                    $visit = Visit::find($targetVisitId);
                    if ($visit) {
                        $visit->fill($transformed['attributes']);
                        $visit->save();
                        if ($cardId && $cardId !== 'dry-run-orphan-card') {
                            if (! DB::table('visit_visitor_card')->where('visit_id', $visit->id)->where('visitor_card_id', $cardId)->exists()) {
                                DB::table('visit_visitor_card')->insert([
                                    'id' => Str::uuid7()->toString(),
                                    'visit_id' => $visit->id,
                                    'visitor_card_id' => $cardId,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }
                    }
                }
                $this->report->incrementUpdated('visits');
            } else {
                if (! $dryRun) {
                    $visit = new Visit($transformed['attributes']);
                    $visit->id = (string) Str::uuid7();
                    $visit->save();
                    $targetVisitId = $visit->id;
                    $this->mappingManager->put('vms-old', 'visits', $sourceKey, $targetVisitId);
                    if ($cardId && $cardId !== 'dry-run-orphan-card') {
                        if (! DB::table('visit_visitor_card')->where('visit_id', $visit->id)->where('visitor_card_id', $cardId)->exists()) {
                            DB::table('visit_visitor_card')->insert([
                                'id' => Str::uuid7()->toString(),
                                'visit_id' => $visit->id,
                                'visitor_card_id' => $cardId,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
                $this->report->incrementInserted('visits');
            }
        }
    }

    protected function importVesselArrivals(string $path, bool $dryRun): void
    {
        $arrivalFilePath = $path.DIRECTORY_SEPARATOR.'ESSVesselTransaction.csv';
        $crewFilePath = $path.DIRECTORY_SEPARATOR.'ESSCrewTransaction.csv';

        if (! File::exists($arrivalFilePath)) {
            return;
        }

        $transformer = new VesselArrivalTransformer;

        $crewRows = [];
        if (File::exists($crewFilePath)) {
            foreach ($this->readCsv($crewFilePath) as $crewRow) {
                $crewRows[] = $crewRow;
            }
        }

        $visitorMap = $this->mappingManager->loadEntityMap('vms-old', 'visitors');

        foreach ($this->readCsv($arrivalFilePath) as $row) {
            $this->report->incrementRead('vessel_arrivals');
            $arrivalData = $transformer->transformArrival($row, $this->locationMap, $this->locationNameMap);
            $sourceKey = $arrivalData['source_key'];

            $targetArrivalId = $this->mappingManager->getTargetId('vms-old', 'vessel_arrivals', $sourceKey);
            $arrival = null;

            if ($targetArrivalId) {
                if (! $dryRun) {
                    $arrival = VesselArrival::find($targetArrivalId);
                    if ($arrival) {
                        $arrival->fill($arrivalData['attributes']);
                        $arrival->save();
                    }
                }
                $this->report->incrementUpdated('vessel_arrivals');
            } else {
                if (! $dryRun) {
                    $arrival = new VesselArrival($arrivalData['attributes']);
                    $arrival->id = (string) Str::uuid7();
                    $arrival->save();
                    $targetArrivalId = $arrival->id;
                    $this->mappingManager->put('vms-old', 'vessel_arrivals', $sourceKey, $targetArrivalId);
                }
                $this->report->incrementInserted('vessel_arrivals');
            }

            if (! $dryRun && $arrival) {
                $crewData = $transformer->extractCrewForArrival($sourceKey, $crewRows, $visitorMap);
                $syncData = [];
                foreach ($crewData['crew_visitor_ids'] as $crewVisId) {
                    $existingPivotId = DB::table('vessel_arrival_crew')
                        ->where('vessel_arrival_id', $arrival->id)
                        ->where('visitor_id', $crewVisId)
                        ->value('id');

                    $syncData[$crewVisId] = [
                        'id' => $existingPivotId ?: Str::uuid7()->toString(),
                    ];
                }
                $arrival->crew()->sync($syncData);

                $this->report->incrementRead('vessel_arrival_crew', count($crewData['crew_visitor_ids']) + count($crewData['unmapped_visitor_ids']));
                $this->report->incrementInserted('vessel_arrival_crew', count($crewData['crew_visitor_ids']));

                if (! empty($crewData['unmapped_visitor_ids'])) {
                    $this->report->incrementSkipped('vessel_arrival_crew', 'missing_visitor_master', count($crewData['unmapped_visitor_ids']));
                }
            }
        }
    }
}
