<?php

namespace App\Services\Import;

use App\Enums\VisitorType;
use App\Models\Staff;
use App\Models\User;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Support\IdentityNumber;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class PilotImporter
{
    public function __construct(
        protected MappingManager $mappingManager,
        protected ImportReport $report
    ) {}

    /**
     * Read CSV rows as associative arrays.
     *
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
            $this->importStaff($path, $dryRun);
            $this->importCards($path, $dryRun);
            $this->importVehicleCards($path, $dryRun);
            $this->importVisitors($path, $dryRun);
            $this->importVisits($path, $dryRun);
            $this->importUsers($path, $dryRun);
        });
    }

    protected function importStaff(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'staffs.csv';
        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('staff');
            $sourceKey = (string) ($row['id'] ?? '');
            $staffId = trim((string) ($row['staff_id'] ?? ''));

            if ($staffId === '') {
                $this->report->incrementSkipped('staff', 'missing_staff_id');

                continue;
            }

            $existing = Staff::where('staff_id', $staffId)->first();
            if ($existing) {
                $this->mappingManager->put('pilot', 'staff', $sourceKey, $existing->id);
                $this->report->incrementUpdated('staff');
            } else {
                $targetId = null;
                if (! $dryRun) {
                    $staff = new Staff([
                        'staff_id' => $staffId,
                        'name' => trim((string) ($row['name'] ?? '')),
                        'department' => trim((string) ($row['department'] ?? '')) ?: null,
                        'is_active' => true,
                    ]);
                    $staff->id = (string) Str::uuid7();
                    $staff->save();
                    $targetId = $staff->id;
                    $this->mappingManager->put('pilot', 'staff', $sourceKey, $targetId);
                }
                $this->report->incrementInserted('staff');
            }
        }
    }

    protected function importCards(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'cards.csv';
        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('cards');
            $sourceKey = (string) ($row['id'] ?? '');
            $cardNo = strtoupper(trim((string) ($row['card_number'] ?? $row['card_no'] ?? '')));

            if ($cardNo === '') {
                $this->report->incrementSkipped('cards', 'missing_card_no');

                continue;
            }

            $existing = VisitorCard::where('card_no', $cardNo)->first();
            if ($existing) {
                $this->mappingManager->put('pilot', 'cards', $sourceKey, $existing->id);
                $this->report->incrementUpdated('cards');
            } else {
                if (! $dryRun) {
                    $card = new VisitorCard([
                        'card_no' => $cardNo,
                        'status' => 'available',
                        'is_active' => true,
                        'note' => 'Diimpor dari data pilot',
                    ]);
                    $card->id = (string) Str::uuid7();
                    $card->save();
                    $this->mappingManager->put('pilot', 'cards', $sourceKey, $card->id);
                }
                $this->report->incrementInserted('cards');
            }
        }
    }

    protected function importVehicleCards(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'vehicle_cards.csv';
        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('vehicle_cards');
            $sourceKey = (string) ($row['id'] ?? '');
            $cardNo = strtoupper(trim((string) ($row['card_number'] ?? $row['card_no'] ?? '')));

            if ($cardNo === '') {
                $this->report->incrementSkipped('vehicle_cards', 'missing_card_no');

                continue;
            }

            $existing = VehicleCard::where('card_no', $cardNo)->first();
            if ($existing) {
                $this->mappingManager->put('pilot', 'vehicle_cards', $sourceKey, $existing->id);
                $this->report->incrementUpdated('vehicle_cards');
            } else {
                if (! $dryRun) {
                    $card = new VehicleCard([
                        'card_no' => $cardNo,
                    ]);
                    $card->id = (string) Str::uuid7();
                    $card->save();
                    $this->mappingManager->put('pilot', 'vehicle_cards', $sourceKey, $card->id);
                }
                $this->report->incrementInserted('vehicle_cards');
            }
        }
    }

    protected function importVisitors(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'visitors.csv';
        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('visitors');
            $sourceKey = (string) ($row['id'] ?? '');

            $rawId = (string) ($row['ktp_number'] ?? $row['id_number'] ?? '');
            $normId = IdentityNumber::normalize($rawId);
            $idType = ! empty($row['id_type']) ? (string) $row['id_type'] : 'ktp';

            $existing = null;
            if ($normId !== '') {
                $existing = Visitor::where('id_type', $idType)->where('id_number', $normId)->first();
            }

            if ($existing) {
                // Merge non-null fields without overwriting existing data
                $updated = false;
                $mergeFields = ['name', 'company', 'birth_date', 'birth_place', 'occupation'];
                foreach ($mergeFields as $field) {
                    if (blank($existing->{$field}) && ! blank($row[$field] ?? null)) {
                        $existing->{$field} = trim((string) $row[$field]);
                        $updated = true;
                    }
                }

                if ($updated && ! $dryRun) {
                    $existing->save();
                }

                $this->mappingManager->put('pilot', 'visitors', $sourceKey, $existing->id);
                $this->report->incrementUpdated('visitors');
            } else {
                // Brand new visitor, assign visitor_no >= 1.000.000 via nextVisitorNo()
                if (! $dryRun) {
                    $visitor = DB::transaction(function () use ($row, $idType, $normId) {
                        $v = new Visitor([
                            'id_type' => $idType,
                            'id_number' => $normId,
                            'name' => trim((string) ($row['name'] ?? '')),
                            'company' => trim((string) ($row['company'] ?? '')) ?: null,
                            'visitor_type' => VisitorType::Visitor,
                            'is_blacklisted' => false,
                            'id_lifetime' => true,
                        ]);
                        $v->id = (string) Str::uuid7();
                        $v->visitor_no = Visitor::nextVisitorNo();
                        $v->save();

                        return $v;
                    });
                    $this->mappingManager->put('pilot', 'visitors', $sourceKey, $visitor->id);
                }
                $this->report->incrementInserted('visitors');
            }
        }
    }

    protected function importVisits(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'visits.csv';
        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('visits');
            $sourceKey = (string) ($row['id'] ?? '');

            $pilotVisitorId = (string) ($row['visitor_id'] ?? '');
            $targetVisitorId = $this->mappingManager->getTargetId('pilot', 'visitors', $pilotVisitorId);

            if (! $targetVisitorId) {
                $this->report->incrementSkipped('visits', 'missing_visitor_mapping');

                continue;
            }

            $pilotStaffId = (string) ($row['staff_id'] ?? '');
            $targetStaffId = $pilotStaffId !== '' ? $this->mappingManager->getTargetId('pilot', 'staff', $pilotStaffId) : null;

            $pilotVehCardId = (string) ($row['vehicle_card_id'] ?? '');
            $targetVehCardId = $pilotVehCardId !== '' ? $this->mappingManager->getTargetId('pilot', 'vehicle_cards', $pilotVehCardId) : null;

            $date = trim((string) ($row['date'] ?? ''));
            $timeIn = trim((string) ($row['time_in'] ?? ''));
            $timeOut = trim((string) ($row['time_out'] ?? ''));

            $checkedOutAt = null;
            if ($timeOut !== '') {
                $inSec = strtotime("{$date} {$timeIn}");
                $outSec = strtotime("{$date} {$timeOut}");

                if ($outSec < $inSec) {
                    $outDate = date('Y-m-d', strtotime($date.' +1 day'));
                } else {
                    $outDate = $date;
                }

                $checkedOutAt = "{$outDate} {$timeOut}";
            }

            $ticketNumber = trim((string) ($row['ticket_number'] ?? ''));

            if (! $dryRun) {
                $visit = new Visit([
                    'visitor_id' => $targetVisitorId,
                    'staff_id' => $targetStaffId,
                    'vehicle_card_id' => $targetVehCardId,
                    'ticket_number' => $ticketNumber ?: null,
                    'date' => $date,
                    'time_in' => $timeIn,
                    'time_out' => $timeOut ?: null,
                    'checked_out_at' => $checkedOutAt,
                    'purpose' => trim((string) ($row['purpose'] ?? '')) ?: null,
                    'location_name' => trim((string) ($row['location_name'] ?? '')) ?: null,
                    'host_name' => trim((string) ($row['host_name'] ?? '')) ?: null,
                    'visitor_company' => trim((string) ($row['visitor_company'] ?? '')) ?: null,
                ]);
                $visit->id = (string) Str::uuid7();
                $visit->save();

                $this->mappingManager->put('pilot', 'visits', $sourceKey, $visit->id);
            }

            $this->report->incrementInserted('visits');
        }
    }

    protected function importUsers(string $path, bool $dryRun): void
    {
        $filePath = $path.DIRECTORY_SEPARATOR.'users.csv';
        foreach ($this->readCsv($filePath) as $row) {
            $this->report->incrementRead('users');
            $sourceKey = (string) ($row['id'] ?? '');
            $email = trim((string) ($row['email'] ?? ''));

            if ($email === '') {
                $this->report->incrementSkipped('users', 'missing_email');

                continue;
            }

            if (User::where('email', $email)->exists()) {
                $this->report->incrementSkipped('users', 'email_exists');

                continue;
            }

            if (! $dryRun) {
                $username = ! empty($row['username'])
                    ? trim((string) $row['username'])
                    : strtolower(explode('@', $email)[0]);

                $user = new User([
                    'name' => trim((string) ($row['name'] ?? '')),
                    'email' => $email,
                    'username' => $username,
                ]);
                $user->id = (string) Str::uuid7();

                // Set raw password hash to prevent double-hashing
                $rawPassword = trim((string) ($row['password'] ?? ''));
                $user->setRawAttributes(array_merge($user->getAttributes(), [
                    'password' => $rawPassword,
                ]));
                $user->save();

                $rolesStr = trim((string) ($row['roles'] ?? ''));
                if ($rolesStr !== '') {
                    $roles = array_filter(array_map('trim', explode(',', $rolesStr)));
                    $user->syncRoles($roles);
                } else {
                    $user->assignRole('security');
                }

                $this->mappingManager->put('pilot', 'users', $sourceKey, $user->id);
            }

            $this->report->incrementInserted('users');
        }
    }
}
