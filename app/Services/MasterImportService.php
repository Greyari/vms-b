<?php

namespace App\Services;

use App\Enums\CardType;
use App\Enums\LocationType;
use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Support\IdentityNumber;
use App\Support\NumberingRetry;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterImportService
{
    public const TYPE_CARD = 'card';

    public const TYPE_VEHICLE_CARD = 'vehicle_card';

    public const TYPE_STAFF = 'staff';

    public const TYPE_LOCATION = 'location';

    public const TYPE_BLACKLIST = 'blacklist';

    public const TYPE_VISITOR = 'visitor';

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
                        'card_no' => 'ASL-2301',
                        'id_card_no' => '0005890502',
                        'name' => 'VISITOR GREEN',
                        'date_issued' => '2023-07-22',
                        'status' => 'available',
                    ]);
                    $writer->addRow([
                        'card_no' => 'ASL-2302-A',
                        'id_card_no' => '0002112144',
                        'name' => 'VISITOR GREEN',
                        'date_issued' => '2025-11-11',
                        'status' => 'available',
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
                        'company' => 'PT VMS Indonesia',
                        'gender' => 'male',
                        'address' => 'Jl. Pelabuhan No. 12',
                    ]);
                    $writer->addRow([
                        'staff_id' => 'STF-002',
                        'name' => 'Siti Rahmawati',
                        'company' => 'PT VMS Indonesia',
                        'gender' => 'female',
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

            case self::TYPE_BLACKLIST:
                return response()->streamDownload(function () {
                    $writer = SimpleExcelWriter::streamDownload('template_blacklist.xlsx');
                    $writer->addRow([
                        'NAME OF OFFENDER' => 'John Doe',
                        'Badge / H.I.D code' => 'HID-10293',
                        'KTP NO' => '1234567890123456',
                        'OFFENCES' => 'Pelanggaran Disiplin Kerja & K3',
                        'DATE' => Carbon::now()->format('Y-m-d'),
                        'REMARK' => 'Dilarang masuk kawasan galangan',
                        'COMPANY NAME' => 'PT Subcon Mandiri',
                    ]);
                    $writer->addRow([
                        'NAME OF OFFENDER' => 'Jane Smith',
                        'Badge / H.I.D code' => 'HID-88392',
                        'KTP NO' => '',
                        'OFFENCES' => 'Membawa barang terlarang',
                        'DATE' => Carbon::now()->format('Y-m-d'),
                        'REMARK' => 'Tanpa KTP (opsional)',
                        'COMPANY NAME' => 'PT Mitra Sejahtera',
                    ]);
                }, 'template_blacklist.xlsx');

            case self::TYPE_VISITOR:
                return response()->streamDownload(function () {
                    $writer = SimpleExcelWriter::streamDownload('template_visitors.xlsx');
                    $writer->addRow([
                        'name' => 'Budi Santoso',
                        'id_type' => 'ktp',
                        'id_number' => '2171012345670001',
                        'company' => 'PT Pelayaran Mandiri',
                        'occupation' => 'Teknisi Kapal',
                        'visitor_type' => 'visitor',
                        'phone' => '081234567890',
                        'email' => 'budi.santoso@example.com',
                        'gender' => 'male',
                        'birth_date' => '1990-05-20',
                        'birth_place' => 'Batam',
                        'nationality' => 'Indonesia',
                        'address' => 'Jl. Duyung No. 12, Batam',
                    ]);
                    $writer->addRow([
                        'name' => 'Michael Chang',
                        'id_type' => 'passport',
                        'id_number' => 'E98765432',
                        'company' => 'Global Marine Survey',
                        'occupation' => 'Marine Inspector',
                        'visitor_type' => 'supplier',
                        'phone' => '+6591234567',
                        'email' => 'michael@example.com',
                        'gender' => 'male',
                        'birth_date' => '1985-11-12',
                        'birth_place' => 'Singapore',
                        'nationality' => 'Singaporean',
                        'address' => '10 Marina Boulevard, Singapore',
                    ]);
                }, 'template_visitors.xlsx');

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

        $attempt = function () use (
            $type,
            $rows,
            $duplicateAction,
            &$created,
            &$updated,
            &$replaced,
            &$failed,
            &$failedDetails
        ) {
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
                        // Each row runs inside its own savepoint (nested transaction).
                        // On PostgreSQL a single failed statement aborts the whole
                        // transaction (SQLSTATE 25P02), so without a savepoint every
                        // following row would fail too. Rolling back to the savepoint
                        // discards only the broken row and lets the import continue.
                        $result = DB::transaction(
                            fn () => $this->persistRow($type, $cleanRow, $analysis, $duplicateAction)
                        );
                        if ($result === 'created') {
                            $created++;
                        } elseif ($result === 'updated') {
                            $updated++;
                        } elseif ($result === 'replaced') {
                            $replaced++;
                        }
                    } catch (\Throwable $e) {
                        if ($e instanceof UniqueConstraintViolationException) {
                            throw $e;
                        }
                        $failed++;
                        $failedDetails[] = [
                            'row_number' => $rowIndex,
                            'identifier' => $analysis['identifier'] ?? '-',
                            'reason' => $e->getMessage(),
                        ];
                    }
                }
            });
        };

        if ($type === self::TYPE_BLACKLIST || $type === self::TYPE_VISITOR) {
            NumberingRetry::run($attempt);
        } else {
            $attempt();
        }

        $success = $created + $updated + $replaced;
        $message = "Import selesai. Berhasil: {$success} (Baru: {$created}, Diperbarui: {$updated}, Diganti: {$replaced}), Gagal: {$failed}.";

        ActivityLogger::logEvent('import_master', [
            'type' => $type,
            'total' => $total,
            'success' => $success,
            'created' => $created,
            'updated' => $updated,
            'replaced' => $replaced,
            'failed' => $failed,
        ]);

        return [
            'message' => $message,
            'total' => $total,
            'success' => $success,
            'created' => $created,
            'updated' => $updated,
            'replaced' => $replaced,
            'failed' => $failed,
            'skipped' => $failed,
            'failed_details' => $failedDetails,
            'errors' => array_map(fn ($d) => $d['reason'], $failedDetails),
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

        if ($extension === 'xls') {
            return $this->readXlsRows($file, $maxRows);
        }

        try {
            $reader = SimpleExcelReader::create($file->getRealPath(), $extension);
            $rows = [];
            foreach ($reader->getRows() as $row) {
                // Skip rows where all cells are empty or whitespace
                $hasContent = false;
                foreach ($row as $v) {
                    if ($v !== null && trim((string) $v) !== '') {
                        $hasContent = true;
                        break;
                    }
                }
                if (! $hasContent) {
                    continue;
                }

                $rows[] = $row;
                if (count($rows) > $maxRows) {
                    throw ValidationException::withMessages([
                        'file' => ["Import aborted: the file exceeds the maximum of {$maxRows} rows."],
                    ]);
                }
            }

            return $rows;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->readXlsRows($file, $maxRows);
        }
    }

    /**
     * Read legacy BIFF8 (.xls) spreadsheet using PhpSpreadsheet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function readXlsRows(UploadedFile $file, int $maxRows): array
    {
        $spreadsheet = IOFactory::load($file->getRealPath());
        $worksheet = $spreadsheet->getActiveSheet();
        $data = $worksheet->toArray(null, true, true, false);

        if (empty($data)) {
            return [];
        }

        $header = array_shift($data);
        $rows = [];

        foreach ($data as $rowValues) {
            $row = [];
            $hasData = false;

            foreach ($header as $colIdx => $colName) {
                $colNameStr = trim((string) $colName);
                if ($colNameStr === '') {
                    continue;
                }
                $val = $rowValues[$colIdx] ?? null;
                if ($val !== null && trim((string) $val) !== '') {
                    $hasData = true;
                }
                $row[$colNameStr] = $val;
            }

            if ($hasData) {
                $rows[] = $row;
                if (count($rows) > $maxRows) {
                    throw ValidationException::withMessages([
                        'file' => ["Import aborted: the file exceeds the maximum of {$maxRows} rows."],
                    ]);
                }
            }
        }

        return $rows;
    }

    /**
     * Clean and normalize keys/values in a raw row.
     *
     * @param  array<string, mixed>  $rawRow
     * @return array<string, mixed>
     */
    private function cleanRow(array $rawRow): array
    {
        $cleaned = [];
        foreach ($rawRow as $key => $value) {
            $cleanKey = strtolower(trim((string) $key));
            $cleanKey = str_replace([' ', '-', '/'], '_', $cleanKey);
            $cleanKey = preg_replace('/_+/', '_', $cleanKey);
            $cleanVal = is_string($value) ? trim($value) : $value;
            $cleaned[$cleanKey] = $cleanVal;
        }

        return $cleaned;
    }

    /**
     * Robust value extraction matching clean and normalized header keys.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $candidates
     */
    public static function extractRowValue(array $row, array $candidates, string $default = ''): string
    {
        foreach ($candidates as $cand) {
            $candClean = strtolower(str_replace([' ', '-', '/'], '_', $cand));
            $candClean = preg_replace('/_+/', '_', $candClean);
            if (isset($row[$candClean]) && $row[$candClean] !== null) {
                $val = trim((string) $row[$candClean]);
                if ($val !== '') {
                    return $val;
                }
            }
        }

        foreach ($candidates as $cand) {
            $normCand = preg_replace('/[^a-z0-9]/', '', strtolower($cand));
            foreach ($row as $k => $v) {
                $normK = preg_replace('/[^a-z0-9]/', '', strtolower((string) $k));
                if ($normK === $normCand && $v !== null) {
                    $val = trim((string) $v);
                    if ($val !== '') {
                        return $val;
                    }
                }
            }
        }

        return $default;
    }

    /**
     * Parse date from various real-world spreadsheet formats (Excel serials, d/m/y, d.m.y, text).
     */
    public static function parseDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || in_array(strtolower($raw), ['-', 'n.a', 'n/a', 'na', 'null', 'not available', '.'], true)) {
            return null;
        }

        // 1. Excel serial number: e.g. 40404.0, 39961, 41480.0
        if (is_numeric($raw)) {
            $num = (float) $raw;
            if ($num >= 10000 && $num <= 80000) {
                try {
                    $dt = Date::excelToDateTimeObject($num);

                    return Carbon::instance($dt)->format('Y-m-d');
                } catch (\Throwable) {
                    // fallback below
                }
            }
        }

        // 2. Remove single quotes before 2-digit years: e.g. "11 Dec '12" -> "11 Dec 2012"
        $clean = preg_replace("/'(\d{2})\b/", '20$1', $raw);
        $clean = trim((string) $clean);

        // 3. Dot-separated date: e.g. "24.10.08", "23.09.14", "20.09.2025"
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $clean, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += ($year > 50 ? 1900 : 2000);
            }
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        // 4. Slash-separated date: e.g. "16/8/08", "28/04/08", "22/01/2024", "13/10/14"
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $clean, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += ($year > 50 ? 1900 : 2000);
            }
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        // 5. Dash-separated date: e.g. "16-8-08", "2026-10-10"
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $clean, $m)) {
            $year = (int) $m[3];
            if ($year < 100) {
                $year += ($year > 50 ? 1900 : 2000);
            }
            $day = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($m[2], 2, '0', STR_PAD_LEFT);

            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        // 6. General Carbon parse
        try {
            return Carbon::parse($clean)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalize gender strings ('M', 'F', 'male', 'female', 'laki-laki', 'perempuan', '1', '2')
     * to single-character 'M' or 'F' matching the char(1) database column.
     */
    public static function parseGender(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = strtolower(trim((string) $value));
        if ($raw === '' || in_array($raw, ['-', 'n/a', 'na', 'null'], true)) {
            return null;
        }

        if (in_array($raw, ['m', 'male', 'laki-laki', 'laki', 'pria', 'l', '1'], true)) {
            return 'M';
        }

        if (in_array($raw, ['f', 'female', 'perempuan', 'wanita', 'p', '2'], true)) {
            return 'F';
        }

        return null;
    }

    /**
     * Parse identity (KTP, Passport, SIM, Badge/Emp Code, or non-KTP fallback).
     *
     * @return array{
     *   id_type: string,
     *   id_number: string,
     *   badge_code: ?string,
     *   has_real_id: bool
     * }
     */
    public static function parseIdentity(string $rawKtp, ?string $rawBadge, string $name): array
    {
        $cleanKtp = preg_replace('/\.0+$/', '', trim($rawKtp));
        $cleanKtp = trim($cleanKtp, " \t\n\r\0\x0B,'\"");

        $cleanBadge = preg_replace('/\.0+$/', '', trim((string) $rawBadge));
        $cleanBadge = trim($cleanBadge, " \t\n\r\0\x0B,'\"");
        if ($cleanBadge === '-') {
            $cleanBadge = '';
        }

        $extractedBadge = null;

        // Check if badge info is embedded inside KTP text (e.g. "2171072409720001, HID : LAE 0076").
        // Word boundaries keep "Temp Pass" from matching "Emp", and the code must contain a digit.
        if (preg_match('/\b(?:H\s*\.?\s*I\s*\.?\s*D|Badge|Emp\s*(?:Code|No)?)\b\s*(?:No)?\s*[:\.]?\s*([A-Za-z0-9_\-\s]+)/i', $cleanKtp, $badgeMatch)
            && preg_match('/\d/', $badgeMatch[1])) {
            $extractedBadge = trim($badgeMatch[1]);
            if (empty($cleanBadge)) {
                $cleanBadge = $extractedBadge;
            }
        }

        // Check if KTP text indicates "Not Available" / dummy / missing
        $isDummyText = false;
        if (
            $cleanKtp === ''
            || in_array(strtolower($cleanKtp), ['-', 'not available', 'identity not available', 'ktp not available', 'temporary pass', 'temp pass', 'temp badge'], true)
            || preg_match('/^(?:not available|identity not available|ktp not available|ktp:\s*not available|ktp on proccess|with time keeping|terminated by|transfer to|release letter|connected to|d\.?o\.?b|date of birth|birth (?:of )?date)/i', $cleanKtp)
        ) {
            $isDummyText = true;
        }

        // 1. Check Passport:
        if (! $isDummyText) {
            // "A944790 passport", "A25717522 (Passport No)"
            if (preg_match('/([A-Z0-9]{6,12})\s*(?:passport|\(passport)/i', $cleanKtp, $pm)) {
                return [
                    'id_type' => 'passport',
                    'id_number' => strtoupper($pm[1]),
                    'badge_code' => $cleanBadge ?: $extractedBadge,
                    'has_real_id' => true,
                ];
            }
            // Singapore NRIC or FIN: S1234567A, T1234567B, etc.
            if (preg_match('/^([STFGVM]\d{7}[A-Z])$/i', $cleanKtp, $pm)) {
                return [
                    'id_type' => 'passport',
                    'id_number' => strtoupper($pm[1]),
                    'badge_code' => $cleanBadge ?: $extractedBadge,
                    'has_real_id' => true,
                ];
            }
            // Passport with birthdate in parens: "B1637440 ( 16.02.1980 )", "C6387317 ( 20.04.1979 )"
            if (preg_match('/^([A-Z]\d{7,8})\s*\(/i', $cleanKtp, $pm)) {
                return [
                    'id_type' => 'passport',
                    'id_number' => strtoupper($pm[1]),
                    'badge_code' => $cleanBadge ?: $extractedBadge,
                    'has_real_id' => true,
                ];
            }
            // Pure passport letter + 6-8 digits: "C7449416", "V822182"
            if (preg_match('/^[A-Z]\d{6,8}$/i', $cleanKtp)) {
                return [
                    'id_type' => 'passport',
                    'id_number' => strtoupper($cleanKtp),
                    'badge_code' => $cleanBadge ?: $extractedBadge,
                    'has_real_id' => true,
                ];
            }
        }

        // 2. Check SIM:
        if (! $isDummyText) {
            if (preg_match('/(\d{10,16})\s*sim/i', $cleanKtp, $sm) || preg_match('/sim\s*[:\.]?\s*(\d{10,16})/i', $cleanKtp, $sm)) {
                return [
                    'id_type' => 'sim',
                    'id_number' => $sm[1],
                    'badge_code' => $cleanBadge ?: $extractedBadge,
                    'has_real_id' => true,
                ];
            }
        }

        // 3. Check KTP Digits (sequence of 10-22 digits, handling spaces/dots/prefixes):
        if (! $isDummyText) {
            $digitsOnly = preg_replace('/\D/', '', $cleanKtp);
            if (strlen($digitsOnly) >= 10 && strlen($digitsOnly) <= 22) {
                return [
                    'id_type' => 'ktp',
                    'id_number' => $digitsOnly,
                    'badge_code' => $cleanBadge ?: $extractedBadge,
                    'has_real_id' => true,
                ];
            }
        }

        // 4. If KTP column had Badge / Emp Code (e.g. "Emp Code : 37647", "Badge No 50064").
        //    Only when it carries an actual number: generic text such as "Temp Pass"
        //    is shared by many different offenders and must not become an identity.
        if (! $isDummyText && preg_match('/(?:Badge|Emp|GTP|Pass)/i', $cleanKtp) && preg_match('/\d/', $cleanKtp)) {
            $badgeFound = $cleanKtp;
            if (empty($cleanBadge)) {
                $cleanBadge = $badgeFound;
            }
            $cleanCode = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $badgeFound));

            return [
                'id_type' => 'ktp',
                'id_number' => self::syntheticId($cleanCode ?: 'BADGE'),
                'badge_code' => $cleanBadge,
                'has_real_id' => false,
            ];
        }

        // 5. No real KTP available: Fallback to non-KTP identifier
        $badgeForId = $cleanBadge ?: $extractedBadge;
        $badgeClean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $badgeForId));
        if ($badgeClean !== '' && preg_match('/\d/', $badgeClean)) {
            $syntheticId = self::syntheticId($badgeClean);
        } else {
            $nameClean = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $name));
            $syntheticId = self::syntheticId($nameClean !== '' ? $nameClean : 'OFFENDER');
        }

        return [
            'id_type' => 'ktp',
            'id_number' => $syntheticId,
            'badge_code' => $cleanBadge ?: $extractedBadge,
            'has_real_id' => false,
        ];
    }

    /**
     * Build the placeholder identity for an offender without a real document.
     *
     * Written in the same spelling the Visitor model stores (IdentityNumber
     * strips dashes), otherwise lookups by id_number never match the saved row
     * and the insert collides with the (id_type, id_number) unique index.
     */
    private static function syntheticId(string $code): string
    {
        return IdentityNumber::normalize('NOKTP'.substr($code, 0, 30));
    }

    /**
     * Analyze a row: check requirements, identify key, find DB match.
     *
     * @param  array<string, mixed>  $row
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
                $cardNo = self::extractRowValue($row, ['card_no', 'nomor_kartu', 'card']);
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
                        'id_card_no' => $existing->id_card_no ?? $existing->absenteeism_no,
                        'name' => $existing->name,
                        'date_issued' => $existing->date_issued?->format('Y-m-d'),
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

                $rawStaffGender = isset($row['gender']) ? trim((string) $row['gender']) : '';
                if ($rawStaffGender !== '' && ! in_array(strtolower($rawStaffGender), ['-', 'n/a', 'na', 'null'], true) && self::parseGender($rawStaffGender) === null) {
                    return [
                        'identifier' => (string) $staffId,
                        'error' => "Baris #{$rowNum}: Nilai gender '{$row['gender']}' tidak valid (gunakan M / F atau male / female).",
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
                        'error' => "Baris #{$rowNum}: Tipe '{$locType}' tidak valid. Pilihan: ".implode(', ', $allowedTypes),
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

            case self::TYPE_BLACKLIST:
                $name = self::extractRowValue($row, ['name_of_offender', 'name', 'offender_name', 'nama']);
                $rawBadge = self::extractRowValue($row, ['badge_/_h.i.d_code', 'badge_/_h.i.d__code', 'badge_h_i_d_code', 'badge_hid_code', 'badge_code', 'badge', 'hid_code', 'hid']);
                $rawKtp = self::extractRowValue($row, ['ktp_no', 'ktp', 'id_number', 'no_ktp', 'nik']);
                $offences = self::extractRowValue($row, ['offences', 'offence', 'reason', 'pelanggaran', 'kasus']);
                $rawDate = self::extractRowValue($row, ['date', 'tanggal', 'blacklisted_at']);
                $remark = self::extractRowValue($row, ['remark', 'remarks', 'keterangan', 'note', 'notes']);
                $company = self::extractRowValue($row, ['company_name', 'company', 'perusahaan']);

                // Fallback name if empty or dash
                if (empty($name) || $name === '-') {
                    if (! empty($remark) && ! preg_match('/^(?:see|refer|banned|warning|sd\/|asl\/|terminated|not available|urgent)/i', $remark)) {
                        $name = $remark;
                    } elseif (! empty($rawBadge)) {
                        $name = 'Offender ('.$rawBadge.')';
                    } elseif (! empty($rawKtp)) {
                        $name = 'Offender ('.$rawKtp.')';
                    } else {
                        return [
                            'identifier' => '-',
                            'error' => "Baris #{$rowNum}: Kolom 'NAME OF OFFENDER' wajib diisi.",
                            'is_duplicate' => false,
                        ];
                    }
                }

                $parsedIdentity = self::parseIdentity($rawKtp, $rawBadge, $name);
                $idNumber = $parsedIdentity['id_number'];
                $badgeCode = $parsedIdentity['badge_code'];

                // Fallback offences if empty
                if (empty($offences)) {
                    $offences = ! empty($remark) ? $remark : 'Blacklisted by Security / Management';
                }

                $parsedDate = self::parseDate($rawDate) ?? Carbon::now()->format('Y-m-d');

                // Duplicate detection:
                // Acuan: KTP NO (jika ada identitas real), Badge / H.I.D code (jika ada), atau Nama (jika tanpa KTP & tanpa badge)
                $existing = null;
                if ($parsedIdentity['has_real_id']) {
                    $existing = Visitor::where('id_number', $idNumber)->first();
                }
                if (! $existing && ! empty($badgeCode)) {
                    $existing = Visitor::where('badge_code', $badgeCode)->first();
                }
                if (! $existing && ! $parsedIdentity['has_real_id']) {
                    $existing = Visitor::where('id_number', $idNumber)
                        ->orWhereRaw('LOWER(name) = ?', [strtolower($name)])
                        ->first();
                }

                $isDuplicate = $existing !== null && $existing->is_blacklisted;

                $displayId = $parsedIdentity['has_real_id'] ? $idNumber : (! empty($badgeCode) ? $badgeCode : 'Tanpa KTP');
                $identifier = $displayId.(! empty($badgeCode) && $parsedIdentity['has_real_id'] ? " ({$badgeCode})" : '');

                return [
                    'identifier' => $identifier,
                    'is_duplicate' => $isDuplicate,
                    'existing_model' => $existing,
                    'existing_data' => $existing ? [
                        'id' => $existing->id,
                        'name' => $existing->name,
                        'id_number' => (! str_starts_with((string) $existing->id_number, 'NOKTP')) ? $existing->id_number : '-',
                        'badge_code' => $existing->badge_code,
                        'offences' => $existing->offences ?? $existing->blacklist_reason,
                        'date' => $existing->blacklisted_at?->format('Y-m-d') ?? $existing->created_at?->format('Y-m-d'),
                        'company' => $existing->company,
                        'remark' => $existing->remark,
                    ] : null,
                ];

            case self::TYPE_VISITOR:
                $rawIdVal = $row['id_number'] ?? ($row['ktp_no'] ?? ($row['no_ktp'] ?? ($row['nik'] ?? null)));
                if (is_int($rawIdVal) || is_float($rawIdVal)) {
                    return [
                        'identifier' => '-',
                        'error' => "Row {$rowNum}: missing or invalid ID Number (numeric-formatted ID cells are refused — format the ID column as Text and re-upload).",
                        'is_duplicate' => false,
                    ];
                }

                $name = self::extractRowValue($row, ['name', 'nama', 'full_name', 'nama_lengkap']);
                $rawIdType = strtolower(self::extractRowValue($row, ['id_type', 'tipe_identitas', 'jenis_id'], ''));
                $idNumber = self::extractRowValue($row, ['id_number', 'nomor_identitas', 'no_ktp', 'nik', 'ktp_no', 'passport_no']);

                if (empty($idNumber) || ! preg_match('/^[0-9A-Za-z\s\.\-_]+$/', $idNumber)) {
                    return [
                        'identifier' => '-',
                        'error' => "Row {$rowNum}: missing or invalid ID Number (numeric-formatted ID cells are refused — format the ID column as Text and re-upload).",
                        'is_duplicate' => false,
                    ];
                }

                $validIdTypes = ['ktp', 'passport', 'sim', 'other'];
                if ($rawIdType === '' || ! in_array($rawIdType, $validIdTypes, true)) {
                    return [
                        'identifier' => '-',
                        'error' => "Row {$rowNum}: unknown ID Type '{$rawIdType}' (expected ktp, sim or passport).",
                        'is_duplicate' => false,
                    ];
                }
                $idType = $rawIdType;

                if (empty($name)) {
                    return [
                        'identifier' => '-',
                        'error' => "Row {$rowNum}: missing Name.",
                        'is_duplicate' => false,
                    ];
                }

                $normalizedId = IdentityNumber::normalize($idNumber);

                $rawGender = self::extractRowValue($row, ['gender', 'jenis_kelamin']);
                if ($rawGender !== '' && ! in_array(strtolower($rawGender), ['-', 'n/a', 'na', 'null'], true) && self::parseGender($rawGender) === null) {
                    return [
                        'identifier' => "{$idType}: {$normalizedId}",
                        'error' => "Baris #{$rowNum}: Nilai gender '{$rawGender}' tidak valid (gunakan M / F atau male / female).",
                        'is_duplicate' => false,
                    ];
                }

                $rawVisitorType = self::extractRowValue($row, ['visitor_type', 'tipe_pengunjung']);
                $allowedVisitorTypes = ['visitor', 'crew', 'temporary_worker', 'supplier', 'others'];
                if (! empty($rawVisitorType)) {
                    $normVisitorType = strtolower($rawVisitorType);
                    if ($normVisitorType === 'regular') {
                        $normVisitorType = 'visitor';
                    }
                    if (! in_array($normVisitorType, $allowedVisitorTypes, true)) {
                        return [
                            'identifier' => "{$idType}: {$normalizedId}",
                            'error' => "Baris #{$rowNum}: Nilai visitor_type '{$rawVisitorType}' tidak valid. Pilihan: ".implode(', ', $allowedVisitorTypes),
                            'is_duplicate' => false,
                        ];
                    }
                }

                $existing = Visitor::where('id_type', $idType)->where('id_number', $normalizedId)->first();

                return [
                    'identifier' => "{$idType}: {$normalizedId}",
                    'is_duplicate' => $existing !== null,
                    'existing_model' => $existing,
                    'existing_data' => $existing ? [
                        'id' => $existing->id,
                        'visitor_no' => $existing->visitor_no,
                        'name' => $existing->name,
                        'id_type' => $existing->id_type,
                        'id_number' => $existing->id_number,
                        'company' => $existing->company,
                        'occupation' => $existing->occupation,
                        'phone' => $existing->phone,
                        'email' => $existing->email,
                        'address' => $existing->address,
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
                $cardNo = (string) (self::extractRowValue($row, ['card_no', 'nomor_kartu']) ?: ($row['card_no'] ?? ''));
                $idCardNo = self::extractRowValue($row, ['id_card_no', 'id_card', 'idcardno', 'absenteeism_no']) ?: null;
                $cardName = self::extractRowValue($row, ['name', 'nama', 'nama_kartu']) ?: null;
                $dateIssued = self::parseDate(self::extractRowValue($row, ['date_issued', 'date_issued_tk', 'date', 'tanggal']));

                $cardType = ! empty($row['card_type']) ? strtolower((string) $row['card_type']) : null;
                if (! $cardType && $cardName) {
                    $lowerName = strtolower($cardName);
                    if (str_contains($lowerName, 'green')) {
                        $cardType = 'visitor_green';
                    } elseif (str_contains($lowerName, 'white')) {
                        $cardType = 'visitor_white';
                    } elseif (str_contains($lowerName, 'crew')) {
                        $cardType = 'crew';
                    } elseif (str_contains($lowerName, 'temp')) {
                        $cardType = 'temporary_worker';
                    } elseif (str_contains($lowerName, 'special')) {
                        $cardType = 'special';
                    } else {
                        $cardType = 'visitor';
                    }
                } elseif (! in_array($cardType, array_map(fn ($c) => $c->value, CardType::cases()))) {
                    $cardType = 'visitor';
                }

                $rawStatus = strtolower(self::extractRowValue($row, ['status']) ?: '');
                $status = match ($rawStatus) {
                    'lost', 'hilang' => 'lost',
                    'damaged', 'rusak' => 'damaged',
                    default => 'available',
                };
                $note = $row['note'] ?? null;

                $data = [
                    'card_no' => $cardNo,
                    'id_card_no' => $idCardNo,
                    'absenteeism_no' => $idCardNo,
                    'name' => $cardName,
                    'date_issued' => $dateIssued,
                    'card_type' => $cardType,
                    'note' => $note,
                ];

                if (! $isDuplicate || ! $model) {
                    VisitorCard::create([
                        ...$data,
                        'status' => $status,
                    ]);

                    return 'created';
                }

                if ($model->trashed()) {
                    $model->restore();
                }

                if ($duplicateAction === 'replace') {
                    $model->fill($data);
                    if ($model->status !== 'in_use' && ! empty($rawStatus)) {
                        $model->status = $status;
                    }
                    $model->save();

                    return 'replaced';
                }

                // Update
                foreach ($data as $k => $v) {
                    if ($v !== null && $v !== '') {
                        $model->{$k} = $v;
                    }
                }
                if ($model->status !== 'in_use' && ! empty($rawStatus)) {
                    $model->status = $status;
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
                $gender = self::parseGender($row['gender'] ?? null);
                $birthDate = self::parseDate($row['birth_date'] ?? null);

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

            case self::TYPE_BLACKLIST:
                $name = self::extractRowValue($row, ['name_of_offender', 'name', 'offender_name', 'nama']);
                $rawBadge = self::extractRowValue($row, ['badge_/_h.i.d_code', 'badge_/_h.i.d__code', 'badge_h_i_d_code', 'badge_hid_code', 'badge_code', 'badge', 'hid_code', 'hid']);
                $rawKtp = self::extractRowValue($row, ['ktp_no', 'ktp', 'id_number', 'no_ktp', 'nik']);
                $offences = self::extractRowValue($row, ['offences', 'offence', 'reason', 'pelanggaran', 'kasus']);
                $rawDate = self::extractRowValue($row, ['date', 'tanggal', 'blacklisted_at']);
                $remark = self::extractRowValue($row, ['remark', 'remarks', 'keterangan', 'note', 'notes']);
                $company = self::extractRowValue($row, ['company_name', 'company', 'perusahaan']);

                if (empty($name) || $name === '-') {
                    if (! empty($remark) && ! preg_match('/^(?:see|refer|banned|warning|sd\/|asl\/|terminated|not available|urgent)/i', $remark)) {
                        $name = $remark;
                    } elseif (! empty($rawBadge)) {
                        $name = 'Offender ('.$rawBadge.')';
                    } elseif (! empty($rawKtp)) {
                        $name = 'Offender ('.$rawKtp.')';
                    } else {
                        $name = 'Unknown Offender';
                    }
                }

                $parsedIdentity = self::parseIdentity($rawKtp, $rawBadge, $name);
                $idType = $parsedIdentity['id_type'];
                $idNumber = $parsedIdentity['id_number'];
                $badgeCode = ! empty($parsedIdentity['badge_code']) ? $parsedIdentity['badge_code'] : null;

                $parsedDate = self::parseDate($rawDate) ?? Carbon::now()->format('Y-m-d');

                if (empty($offences)) {
                    $offences = ! empty($remark) ? $remark : 'Blacklisted by Security / Management';
                }

                $data = [
                    'name' => $name,
                    'id_type' => $idType,
                    'id_number' => $idNumber,
                    'badge_code' => $badgeCode,
                    'offences' => $offences,
                    'blacklist_reason' => $offences,
                    'blacklisted_at' => $parsedDate,
                    'remark' => $remark !== '' ? $remark : null,
                    'company' => $company !== '' ? $company : null,
                    'is_blacklisted' => true,
                ];

                // A row without a real document only carries a NOKTP placeholder.
                // When it matches a visitor that already has an identity, keep that
                // identity: overwriting it would wipe a real KTP number and could
                // collide with another row's placeholder on the unique index.
                $withoutPlaceholder = function (Visitor $target) use ($data, $parsedIdentity): array {
                    if (! $parsedIdentity['has_real_id'] && ! empty($target->id_number)) {
                        unset($data['id_type'], $data['id_number']);
                    }

                    return $data;
                };

                if (! $isDuplicate || ! $model) {
                    $existingVisitor = null;
                    if ($parsedIdentity['has_real_id']) {
                        $existingVisitor = Visitor::where('id_number', $idNumber)->first();
                    }
                    if (! $existingVisitor && $badgeCode !== null) {
                        $existingVisitor = Visitor::where('badge_code', $badgeCode)->first();
                    }
                    if (! $existingVisitor && ! $parsedIdentity['has_real_id']) {
                        $existingVisitor = Visitor::where('id_number', $idNumber)
                            ->orWhereRaw('LOWER(name) = ?', [strtolower($name)])
                            ->first();
                    }

                    if ($existingVisitor) {
                        $existingVisitor->forceFill($withoutPlaceholder($existingVisitor))->save();

                        return 'created';
                    }

                    $newVisitor = new Visitor;
                    $newVisitor->forceFill($data)->save();

                    return 'created';
                }

                if ($duplicateAction === 'replace') {
                    $model->forceFill($withoutPlaceholder($model))->save();

                    return 'replaced';
                }

                // Update
                $model->name = $name;
                if ($parsedIdentity['has_real_id'] || empty($model->id_number)) {
                    $model->id_type = $idType;
                    $model->id_number = $idNumber;
                }
                if ($badgeCode !== null) {
                    $model->badge_code = $badgeCode;
                }
                if ($offences !== '') {
                    $model->offences = $offences;
                    $model->blacklist_reason = $offences;
                }
                if ($parsedDate !== null) {
                    $model->blacklisted_at = $parsedDate;
                }
                if ($remark !== '') {
                    $model->remark = $remark;
                }
                if ($company !== '') {
                    $model->company = $company;
                }
                $model->is_blacklisted = true;
                $model->save();

                return 'updated';

            case self::TYPE_VISITOR:
                $name = self::extractRowValue($row, ['name', 'nama', 'full_name', 'nama_lengkap']);
                $rawIdType = strtolower(self::extractRowValue($row, ['id_type', 'tipe_identitas', 'jenis_id'], 'ktp'));
                if (! in_array($rawIdType, ['ktp', 'passport', 'sim', 'other'], true)) {
                    $rawIdType = 'ktp';
                }
                $idNumber = IdentityNumber::normalize(self::extractRowValue($row, ['id_number', 'nomor_identitas', 'no_ktp', 'nik', 'ktp_no', 'passport_no']));
                $company = self::extractRowValue($row, ['company', 'perusahaan']);
                $occupation = self::extractRowValue($row, ['occupation', 'jabatan', 'profesi']);
                $phone = self::extractRowValue($row, ['phone', 'telepon', 'no_hp']);
                $email = self::extractRowValue($row, ['email', 'surel']);
                $birthPlace = self::extractRowValue($row, ['birth_place', 'tempat_lahir']);
                $rawBirthDate = self::extractRowValue($row, ['birth_date', 'tanggal_lahir', 'tgl_lahir']);
                $birthDate = self::parseDate($rawBirthDate);
                $nationality = self::extractRowValue($row, ['nationality', 'kewarganegaraan'], 'Indonesia');
                $address = self::extractRowValue($row, ['address', 'alamat', 'alamat_domisili']);

                $gender = self::parseGender(self::extractRowValue($row, ['gender', 'jenis_kelamin']));

                $rawVisitorType = strtolower(self::extractRowValue($row, ['visitor_type', 'tipe_pengunjung'], 'visitor'));
                if ($rawVisitorType === 'regular') {
                    $rawVisitorType = 'visitor';
                }
                if (! in_array($rawVisitorType, ['visitor', 'crew', 'temporary_worker', 'supplier', 'others'], true)) {
                    $rawVisitorType = 'visitor';
                }

                $data = [
                    'name' => $name,
                    'id_type' => $rawIdType,
                    'id_number' => $idNumber,
                    'company' => $company !== '' ? $company : null,
                    'occupation' => $occupation !== '' ? $occupation : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'email' => $email !== '' ? $email : null,
                    'birth_place' => $birthPlace !== '' ? $birthPlace : null,
                    'birth_date' => $birthDate,
                    'nationality' => $nationality !== '' ? $nationality : 'Indonesia',
                    'gender' => $gender,
                    'visitor_type' => $rawVisitorType,
                    'address' => $address !== '' ? $address : null,
                ];

                if (! $isDuplicate || ! $model) {
                    $visitor = new Visitor;
                    $visitor->forceFill($data);
                    $visitor->save();

                    return 'created';
                }

                if ($duplicateAction === 'replace') {
                    $model->forceFill($data)->save();

                    return 'replaced';
                }

                // Update mode
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
