<?php

namespace App\Services;

use App\Enums\CardType;
use App\Enums\LocationType;
use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\VisitorCard;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterImportService
{
    public const TYPE_CARD = 'card';
    public const TYPE_VEHICLE_CARD = 'vehicle_card';
    public const TYPE_STAFF = 'staff';
    public const TYPE_LOCATION = 'location';

    /**
     * Generate template Excel stream download for specified master data.
     */
    public function downloadTemplate(string $type): StreamedResponse
    {
        switch ($type) {
            case self::TYPE_CARD:
                return response()->streamDownload(function () {
                    $writer = SimpleExcelWriter::streamDownload('template_cards.xlsx');
                    $writer->addRow([
                        'card_no' => 'VC-001',
                        'card_type' => 'visitor',
                        'note' => 'Kartu Tamu Reguler',
                    ]);
                    $writer->addRow([
                        'card_no' => 'VC-002',
                        'card_type' => 'vip',
                        'note' => 'Kartu VIP Khusus',
                    ]);
                }, 'template_cards.xlsx');

            case self::TYPE_VEHICLE_CARD:
                return response()->streamDownload(function () {
                    $writer = SimpleExcelWriter::streamDownload('template_vehicle_cards.xlsx');
                    $writer->addRow([
                        'card_no' => 'VH-001',
                        'status' => 'available',
                    ]);
                    $writer->addRow([
                        'card_no' => 'VH-002',
                        'status' => 'available',
                    ]);
                }, 'template_vehicle_cards.xlsx');

            case self::TYPE_STAFF:
                return response()->streamDownload(function () {
                    $writer = SimpleExcelWriter::streamDownload('template_staff.xlsx');
                    $writer->addRow([
                        'staff_id' => 'STF-001',
                        'name' => 'Budi Santoso',
                        'department' => 'Operasional',
                        'company' => 'PT VMS Indonesia',
                        'occupation' => 'Staff Lapangan',
                        'gender' => 'male',
                        'birth_date' => '1990-05-20',
                        'address' => 'Jl. Pelabuhan No. 12',
                    ]);
                    $writer->addRow([
                        'staff_id' => 'STF-002',
                        'name' => 'Siti Rahmawati',
                        'department' => 'HSE',
                        'company' => 'PT VMS Indonesia',
                        'occupation' => 'Safety Officer',
                        'gender' => 'female',
                        'birth_date' => '1993-08-14',
                        'address' => 'Jl. Yos Sudarso No. 45',
                    ]);
                }, 'template_staff.xlsx');

            case self::TYPE_LOCATION:
                return response()->streamDownload(function () {
                    $writer = SimpleExcelWriter::streamDownload('template_locations_vessels.xlsx');
                    $writer->addRow([
                        'name' => 'Dermaga Barat 01',
                        'type' => 'location',
                        'code' => '',
                        'pic' => 'Ahmad Dahlan',
                        'call_sign' => '',
                        'flag' => '',
                        'port' => 'Batam',
                        'captain' => '',
                        'local_agent_company' => '',
                    ]);
                    $writer->addRow([
                        'name' => 'KM Surya Bahari',
                        'type' => 'repair_vessel',
                        'code' => 'IMO9234567',
                        'pic' => '',
                        'call_sign' => 'YBD99',
                        'flag' => 'Indonesia',
                        'port' => 'Batam',
                        'captain' => 'Capt. Hendra',
                        'local_agent_company' => 'PT Pelayaran Nusantara',
                    ]);
                }, 'template_locations_vessels.xlsx');

            default:
                throw new \InvalidArgumentException("Unknown import type: {$type}");
        }
    }

    /**
     * Preview rows from uploaded file and detect duplicates without committing to DB.
     *
     * @return array{
     *   total_rows: int,
     *   preview: array<int, array<string, mixed>>,
     *   duplicates: array<int, array<string, mixed>>,
     *   duplicate_count: int,
     *   new_count: int,
     *   error_count: int,
     *   errors: array<int, array<string, mixed>>
     * }
     */
    public function preview(string $type, UploadedFile $file): array
    {
        $rows = $this->readRows($file);
        $preview = [];
        $duplicates = [];
        $errors = [];
        $newCount = 0;

        $rowIndex = 1; // 1-indexed, line 1 is header, data starts at line 2
        foreach ($rows as $rawRow) {
            $rowIndex++;
            $cleanRow = $this->cleanRow($rawRow);

            // Validation & duplicate check per type
            $rowAnalysis = $this->analyzeRow($type, $cleanRow, $rowIndex);

            if (! empty($rowAnalysis['error'])) {
                $errors[] = [
                    'row_number' => $rowIndex,
                    'message' => $rowAnalysis['error'],
                    'data' => $cleanRow,
                ];
                $preview[] = array_merge($cleanRow, [
                    '_row' => $rowIndex,
                    '_status' => 'error',
                    '_error' => $rowAnalysis['error'],
                ]);
            } elseif ($rowAnalysis['is_duplicate']) {
                $duplicates[] = [
                    'row_number' => $rowIndex,
                    'identifier' => $rowAnalysis['identifier'],
                    'existing_data' => $rowAnalysis['existing_data'],
                    'new_data' => $cleanRow,
                ];
                $preview[] = array_merge($cleanRow, [
                    '_row' => $rowIndex,
                    '_status' => 'duplicate',
                    '_identifier' => $rowAnalysis['identifier'],
                ]);
            } else {
                $newCount++;
                $preview[] = array_merge($cleanRow, [
                    '_row' => $rowIndex,
                    '_status' => 'new',
                    '_identifier' => $rowAnalysis['identifier'],
                ]);
            }
        }

        return [
            'total_rows' => count($rows),
            'preview' => $preview,
            'duplicates' => $duplicates,
            'duplicate_count' => count($duplicates),
            'new_count' => $newCount,
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Execute the import with the user's chosen duplicate action ('update' or 'replace').
     *
     * @return array{
     *   message: string,
     *   total: int,
     *   success: int,
     *   created: int,
     *   updated: int,
     *   replaced: int,
     *   failed: int,
     *   failed_details: array<int, array<string, mixed>>
     * }
     */
    public function import(string $type, UploadedFile $file, string $duplicateAction = 'update'): array
    {
        $rows = $this->readRows($file);
        $total = count($rows);
        $created = 0;
        $updated = 0;
        $replaced = 0;
        $failed = 0;
        $failedDetails = [];

        DB::transaction(function () use (
            $type,
            $rows,
            $duplicateAction,
            &$created,
            &$updated,
            &$replaced,
            &$failed,
            &$failedDetails
        ) {
            $rowIndex = 1;
            foreach ($rows as $rawRow) {
                $rowIndex++;
                $cleanRow = $this->cleanRow($rawRow);

                $analysis = $this->analyzeRow($type, $cleanRow, $rowIndex);

                if (! empty($analysis['error'])) {
                    $failed++;
                    $failedDetails[] = [
                        'row_number' => $rowIndex,
                        'identifier' => $analysis['identifier'] ?? '-',
                        'reason' => $analysis['error'],
                    ];
                    continue;
                }

                try {
                    $result = $this->persistRow($type, $cleanRow, $analysis, $duplicateAction);
                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    } elseif ($result === 'replaced') {
                        $replaced++;
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $failedDetails[] = [
                        'row_number' => $rowIndex,
                        'identifier' => $analysis['identifier'] ?? '-',
                        'reason' => $e->getMessage(),
                    ];
                }
            }
        });

        $success = $created + $updated + $replaced;
        $message = "Import selesai. Berhasil: {$success} (Baru: {$created}, Diperbarui: {$updated}, Diganti: {$replaced}), Gagal: {$failed}.";

        return [
            'message' => $message,
            'total' => $total,
            'success' => $success,
            'created' => $created,
            'updated' => $updated,
            'replaced' => $replaced,
            'failed' => $failed,
            'failed_details' => $failedDetails,
        ];
    }

    /**
     * Read all rows from the uploaded Excel/CSV file.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readRows(UploadedFile $file): array
    {
        $maxRows = (int) config('vms.import_max_rows', 2000);
        $extension = strtolower($file->getClientOriginalExtension());
        $reader = SimpleExcelReader::create($file->getRealPath(), $extension);

        $rows = [];
        foreach ($reader->getRows() as $row) {
            $rows[] = $row;
            if (count($rows) > $maxRows) {
                throw ValidationException::withMessages([
                    'file' => ["File melebihi batas maksimum {$maxRows} baris."],
                ]);
            }
        }

        return $rows;
    }

    /**
     * Clean and normalize keys/values in a raw row.
     *
     * @param array<string, mixed> $rawRow
     * @return array<string, mixed>
     */
    private function cleanRow(array $rawRow): array
    {
        $cleaned = [];
        foreach ($rawRow as $key => $value) {
            $cleanKey = strtolower(trim((string) $key));
            $cleanKey = str_replace([' ', '-'], '_', $cleanKey);
            $cleanVal = is_string($value) ? trim($value) : $value;
            $cleaned[$cleanKey] = $cleanVal;
        }
        return $cleaned;
    }

    /**
     * Analyze a row: check requirements, identify key, find DB match.
     *
     * @param array<string, mixed> $row
     * @return array{
     *   identifier: string,
     *   error?: string,
     *   is_duplicate: bool,
     *   existing_model?: mixed,
     *   existing_data?: array<string, mixed>
     * }
     */
    private function analyzeRow(string $type, array $row, int $rowNum): array
    {
        switch ($type) {
            case self::TYPE_CARD:
                $cardNo = $row['card_no'] ?? null;
                if (empty($cardNo)) {
                    return [
                        'identifier' => '-',
                        'error' => "Baris #{$rowNum}: Kolom 'card_no' wajib diisi.",
                        'is_duplicate' => false,
                    ];
                }

                $existing = VisitorCard::withTrashed()->where('card_no', (string) $cardNo)->first();
                return [
                    'identifier' => (string) $cardNo,
                    'is_duplicate' => $existing !== null,
                    'existing_model' => $existing,
                    'existing_data' => $existing ? [
                        'id' => $existing->id,
                        'card_no' => $existing->card_no,
                        'card_type' => $existing->card_type instanceof CardType ? $existing->card_type->value : $existing->card_type,
                        'status' => $existing->status,
                        'note' => $existing->note,
                    ] : null,
                ];

            case self::TYPE_VEHICLE_CARD:
                $cardNo = $row['card_no'] ?? null;
                if (empty($cardNo)) {
                    return [
                        'identifier' => '-',
                        'error' => "Baris #{$rowNum}: Kolom 'card_no' wajib diisi.",
                        'is_duplicate' => false,
                    ];
                }

                $existing = VehicleCard::withTrashed()->where('card_no', (string) $cardNo)->first();
                return [
                    'identifier' => (string) $cardNo,
                    'is_duplicate' => $existing !== null,
                    'existing_model' => $existing,
                    'existing_data' => $existing ? [
                        'id' => $existing->id,
                        'card_no' => $existing->card_no,
                        'status' => $existing->status,
                    ] : null,
                ];

            case self::TYPE_STAFF:
                $staffId = $row['staff_id'] ?? null;
                $name = $row['name'] ?? null;
                if (empty($staffId)) {
                    return [
                        'identifier' => '-',
                        'error' => "Baris #{$rowNum}: Kolom 'staff_id' wajib diisi.",
                        'is_duplicate' => false,
                    ];
                }
                if (empty($name)) {
                    return [
                        'identifier' => (string) $staffId,
                        'error' => "Baris #{$rowNum}: Kolom 'name' wajib diisi.",
                        'is_duplicate' => false,
                    ];
                }

                if (! empty($row['gender']) && ! in_array(strtolower((string) $row['gender']), ['male', 'female', 'laki-laki', 'perempuan'])) {
                    return [
                        'identifier' => (string) $staffId,
                        'error' => "Baris #{$rowNum}: Nilai gender '{$row['gender']}' tidak valid (gunakan male / female).",
                        'is_duplicate' => false,
                    ];
                }

                $existing = Staff::withTrashed()->where('staff_id', (string) $staffId)->first();
                return [
                    'identifier' => (string) $staffId,
                    'is_duplicate' => $existing !== null,
                    'existing_model' => $existing,
                    'existing_data' => $existing ? [
                        'id' => $existing->id,
                        'staff_id' => $existing->staff_id,
                        'name' => $existing->name,
                        'department' => $existing->department,
                        'company' => $existing->company,
                    ] : null,
                ];

            case self::TYPE_LOCATION:
                $name = $row['name'] ?? null;
                $locType = strtolower(trim((string) ($row['type'] ?? 'location')));
                if (empty($name)) {
                    return [
                        'identifier' => '-',
                        'error' => "Baris #{$rowNum}: Kolom 'name' wajib diisi.",
                        'is_duplicate' => false,
                    ];
                }

                $allowedTypes = array_map(fn ($case) => $case->value, LocationType::cases());
                if (! in_array($locType, $allowedTypes)) {
                    return [
                        'identifier' => (string) $name,
                        'error' => "Baris #{$rowNum}: Tipe '{$locType}' tidak valid. Pilihan: " . implode(', ', $allowedTypes),
                        'is_duplicate' => false,
                    ];
                }

                // If location, code is derived from name. If vessel, code is IMO/code given or name code.
                $code = ! empty($row['code']) ? trim((string) $row['code']) : Location::codeFromName((string) $name);

                $existing = Location::withTrashed()->where('code', $code)->first();
                if (! $existing && $locType === LocationType::Location->value) {
                    $existing = Location::withTrashed()->where('name', $name)->first();
                }

                return [
                    'identifier' => $code ?: (string) $name,
                    'is_duplicate' => $existing !== null,
                    'existing_model' => $existing,
                    'existing_data' => $existing ? [
                        'id' => $existing->id,
                        'name' => $existing->name,
                        'type' => $existing->type instanceof LocationType ? $existing->type->value : $existing->type,
                        'code' => $existing->code,
                        'pic' => $existing->pic,
                    ] : null,
                ];

            default:
                throw new \InvalidArgumentException("Unknown type: {$type}");
        }
    }

    /**
     * Persist a row to database (create, update, or replace).
     */
    private function persistRow(string $type, array $row, array $analysis, string $duplicateAction): string
    {
        $isDuplicate = $analysis['is_duplicate'];
        $model = $analysis['existing_model'] ?? null;

        switch ($type) {
            case self::TYPE_CARD:
                $cardNo = (string) $row['card_no'];
                $cardType = ! empty($row['card_type']) ? strtolower((string) $row['card_type']) : 'visitor';
                if (! in_array($cardType, array_map(fn ($c) => $c->value, CardType::cases()))) {
                    $cardType = 'visitor';
                }
                $note = $row['note'] ?? null;

                if (! $isDuplicate || ! $model) {
                    VisitorCard::create([
                        'card_no' => $cardNo,
                        'card_type' => $cardType,
                        'status' => 'available',
                        'note' => $note,
                    ]);
                    return 'created';
                }

                if ($model->trashed()) {
                    $model->restore();
                }

                // Decision: Card status belongs to check-in/check-out; do not touch status of active cards.
                if ($duplicateAction === 'replace') {
                    $model->card_type = $cardType;
                    $model->note = $note;
                    $model->save();
                    return 'replaced';
                }

                // Update
                if (! empty($row['card_type'])) {
                    $model->card_type = $cardType;
                }
                if (array_key_exists('note', $row)) {
                    $model->note = $note;
                }
                $model->save();
                return 'updated';

            case self::TYPE_VEHICLE_CARD:
                $cardNo = (string) $row['card_no'];
                $status = ! empty($row['status']) && in_array(strtolower((string) $row['status']), ['available', 'in_use'])
                    ? strtolower((string) $row['status'])
                    : 'available';

                if (! $isDuplicate || ! $model) {
                    VehicleCard::create([
                        'card_no' => $cardNo,
                        'status' => $status,
                    ]);
                    return 'created';
                }

                if ($model->trashed()) {
                    $model->restore();
                }

                if ($duplicateAction === 'replace') {
                    $model->status = $status;
                    $model->save();
                    return 'replaced';
                }

                // Update
                if (! empty($row['status'])) {
                    $model->status = $status;
                }
                $model->save();
                return 'updated';

            case self::TYPE_STAFF:
                $staffId = (string) $row['staff_id'];
                $name = (string) $row['name'];
                $gender = ! empty($row['gender']) ? (in_array(strtolower((string) $row['gender']), ['female', 'perempuan']) ? 'female' : 'male') : null;
                $birthDate = ! empty($row['birth_date']) ? Carbon::parse($row['birth_date'])->format('Y-m-d') : null;

                $data = [
                    'staff_id' => $staffId,
                    'name' => $name,
                    'department' => $row['department'] ?? null,
                    'company' => $row['company'] ?? null,
                    'occupation' => $row['occupation'] ?? null,
                    'gender' => $gender,
                    'birth_date' => $birthDate,
                    'address' => $row['address'] ?? null,
                ];

                if (! $isDuplicate || ! $model) {
                    Staff::create($data);
                    return 'created';
                }

                if ($model->trashed()) {
                    $model->restore();
                }

                if ($duplicateAction === 'replace') {
                    $model->fill($data);
                    $model->save();
                    return 'replaced';
                }

                // Update: only update non-empty fields
                foreach ($data as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $model->{$k} = $v;
                    }
                }
                $model->save();
                return 'updated';

            case self::TYPE_LOCATION:
                $name = (string) $row['name'];
                $locType = strtolower(trim((string) ($row['type'] ?? 'location')));
                $code = ! empty($row['code']) ? trim((string) $row['code']) : Location::codeFromName($name);

                $data = [
                    'name' => $name,
                    'type' => $locType,
                    'code' => $code,
                    'call_sign' => $row['call_sign'] ?? null,
                    'sign' => $row['sign'] ?? null,
                    'flag' => $row['flag'] ?? null,
                    'port' => $row['port'] ?? null,
                    'captain' => $row['captain'] ?? null,
                    'pic' => $row['pic'] ?? null,
                    'local_agent_company' => $row['local_agent_company'] ?? null,
                    'local_agent_name' => $row['local_agent_name'] ?? null,
                    'local_agent_phone' => $row['local_agent_phone'] ?? null,
                ];

                if (! $isDuplicate || ! $model) {
                    Location::create($data);
                    return 'created';
                }

                if ($model->trashed()) {
                    $model->restore();
                }

                if ($duplicateAction === 'replace') {
                    $model->fill($data);
                    $model->save();
                    return 'replaced';
                }

                // Update
                foreach ($data as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $model->{$k} = $v;
                    }
                }
                $model->save();
                return 'updated';

            default:
                throw new \InvalidArgumentException("Unknown type: {$type}");
        }
    }
}
