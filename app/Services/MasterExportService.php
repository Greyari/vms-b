<?php

namespace App\Services;

use App\Enums\CardType;
use App\Enums\LocationType;
use App\Enums\VisitorType;
use App\Models\Blacklist;
use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MasterExportService
{
    /**
     * Escape spreadsheet formula injection characters (=, +, -, @, \t, \r).
     */
    public static function escapeFormula(mixed $value): mixed
    {
        if (is_string($value) && $value !== '') {
            $first = $value[0];
            if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
                return "'".$value;
            }
        }

        return $value;
    }

    /**
     * Build the Eloquent query for the specified master entity based on request filters.
     */
    public function buildQuery(string $entity, Request $request): Builder
    {
        switch ($entity) {
            case 'visitors':
            case 'visitor':
                $query = Visitor::query()->where('is_blacklisted', false)->with('vessel');

                $search = trim((string) $request->input('search', ''));
                if ($search !== '') {
                    $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
                    $query->where(fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(id_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(company) LIKE ?', [$term]));
                }

                if ($request->filled('visitor_type')) {
                    $query->where('visitor_type', $request->input('visitor_type'));
                }

                $this->applyDateFilter($query, $request, 'created_at');

                return $query->orderBy('visitor_no');

            case 'blacklist':
            case 'blacklisted':
                $query = Blacklist::query()->where('is_active', true);

                $search = trim((string) $request->input('search', ''));
                if ($search !== '') {
                    $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
                    $query->where(fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(id_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(company) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(offences) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(badge_code) LIKE ?', [$term]));
                }

                $this->applyDateFilter($query, $request, 'blacklisted_at');

                return $query->orderBy('name');

            case 'cards':
            case 'card':
                $query = VisitorCard::query();

                $search = trim((string) $request->input('search', ''));
                if ($search !== '') {
                    $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
                    $query->where(fn (Builder $q) => $q->whereRaw('LOWER(card_no) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(absenteeism_no) LIKE ?', [$term]));
                }

                if ($request->filled('card_type')) {
                    $query->where('card_type', $request->input('card_type'));
                }

                if ($request->filled('status')) {
                    $query->where('status', $request->input('status'));
                }

                if ($request->filled('is_active')) {
                    $query->where('is_active', $request->boolean('is_active'));
                }

                $this->applyDateFilter($query, $request, 'created_at');

                return $query->orderBy('card_no');

            case 'vehicle_cards':
            case 'vehicle-cards':
            case 'vehicle_card':
                $query = VehicleCard::query();

                $search = trim((string) $request->input('search', ''));
                if ($search !== '') {
                    $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
                    $query->whereRaw('LOWER(card_no) LIKE ?', [$term]);
                }

                if ($request->filled('status')) {
                    $query->where('status', $request->input('status'));
                }

                $this->applyDateFilter($query, $request, 'created_at');

                return $query->orderBy('card_no');

            case 'staffs':
            case 'staff':
                $query = Staff::query();

                $search = trim((string) $request->input('search', ''));
                if ($search !== '') {
                    $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
                    $query->where(fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(staff_id) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(department) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(company) LIKE ?', [$term]));
                }

                if ($request->filled('department')) {
                    $query->where('department', $request->input('department'));
                }

                if ($request->filled('company')) {
                    $term = '%'.strtolower(addcslashes(trim((string) $request->input('company')), '%_\\')).'%';
                    $query->whereRaw('LOWER(company) LIKE ?', [$term]);
                }

                if ($request->filled('gender')) {
                    $query->where('gender', $request->input('gender'));
                }

                $this->applyDateFilter($query, $request, 'created_at');

                return $query->orderBy('name');

            case 'locations':
            case 'location':
                $query = Location::query();

                $search = trim((string) $request->input('search', ''));
                if ($search !== '') {
                    $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
                    $query->where(fn (Builder $q) => $q->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(code) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(pic) LIKE ?', [$term]));
                }

                if ($request->filled('type')) {
                    $query->where('type', $request->input('type'));
                }

                if ($request->filled('is_active')) {
                    $query->where('is_active', $request->boolean('is_active'));
                }

                $this->applyDateFilter($query, $request, 'created_at');

                return $query->orderBy('name');

            default:
                throw new \InvalidArgumentException("Unknown entity: {$entity}");
        }
    }

    /**
     * Apply date range filter on a specified column.
     */
    protected function applyDateFilter(Builder $query, Request $request, string $defaultField): void
    {
        $field = $request->input('date_field', $defaultField);

        // Whitelist allowed date fields
        $allowedFields = ['created_at', 'updated_at', 'blacklisted_at', 'birth_date', 'date', 'id_issued_at', 'id_expires_at'];
        if (! in_array($field, $allowedFields, true)) {
            $field = $defaultField;
        }

        $startDate = $request->input('start_date', $request->input('date_from'));
        $endDate = $request->input('end_date', $request->input('date_to'));

        if (! empty($startDate) && ! empty($endDate)) {
            try {
                $from = Carbon::parse($startDate)->startOfDay();
                $to = Carbon::parse($endDate)->endOfDay();
                $query->whereBetween($field, [$from, $to]);
            } catch (\Throwable) {
                // Invalid date format ignored
            }
        } elseif (! empty($startDate)) {
            try {
                $from = Carbon::parse($startDate)->startOfDay();
                $query->where($field, '>=', $from);
            } catch (\Throwable) {
            }
        } elseif (! empty($endDate)) {
            try {
                $to = Carbon::parse($endDate)->endOfDay();
                $query->where($field, '<=', $to);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Return row count preview for export.
     */
    public function count(string $entity, Request $request): int
    {
        return $this->buildQuery($entity, $request)->count();
    }

    /**
     * Stream export rows in XLSX or CSV format.
     */
    public function export(string $entity, Request $request): StreamedResponse
    {
        $query = $this->buildQuery($entity, $request);

        $format = strtolower($request->input('format', 'xlsx'));
        if (! in_array($format, ['xlsx', 'csv'], true)) {
            $format = 'xlsx';
        }

        $startDate = $request->input('start_date', $request->input('date_from'));
        $endDate = $request->input('end_date', $request->input('date_to'));

        $defaultBaseName = match ($entity) {
            'blacklist', 'blacklisted' => 'blacklisted_visitors',
            'vehicle_cards', 'vehicle-cards', 'vehicle_card' => 'vehicle_cards',
            'staffs', 'staff' => 'staffs',
            'cards', 'card' => 'cards',
            'visitors', 'visitor' => 'visitors',
            'locations', 'location' => 'locations',
            default => "{$entity}_export",
        };

        if (! empty($startDate) && ! empty($endDate)) {
            $filename = "{$defaultBaseName}_{$startDate}_to_{$endDate}.{$format}";
        } else {
            $filename = "{$defaultBaseName}.{$format}";
        }

        $selectedColumns = $request->input('columns');
        if (is_string($selectedColumns)) {
            $selectedColumns = explode(',', $selectedColumns);
        }
        if (! is_array($selectedColumns) || empty($selectedColumns)) {
            $selectedColumns = null; // Use default
        } else {
            $selectedColumns = array_filter(array_map('trim', $selectedColumns));
        }

        $lang = $request->input('lang', 'en');

        ActivityLogger::logEvent('export_master', [
            'entity' => $entity,
            'format' => $format,
            'columns' => $selectedColumns,
            'date_field' => $request->input('date_field'),
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        return response()->streamDownload(function () use ($query, $entity, $filename, $selectedColumns, $lang) {
            $writer = SimpleExcelWriter::streamDownload($filename);
            $rowNo = 1;

            $query->lazy(1000)->each(function ($model) use ($writer, $entity, $selectedColumns, $lang, &$rowNo): void {
                $mapped = $this->mapModelToRow($entity, $model, $selectedColumns, $lang, $rowNo++);
                $writer->addRow($mapped);
            });
        }, $filename);
    }

    /**
     * Map model instance to associative array row for spreadsheet.
     *
     * @return array<string, mixed>
     */
    protected function mapModelToRow(string $entity, mixed $model, ?array $selectedColumns, string $lang, int $rowNo): array
    {
        $data = [];

        switch ($entity) {
            case 'visitors':
            case 'visitor':
                /** @var Visitor $model */
                $fullMap = [
                    'visitor_no' => ['label' => ['en' => 'Visitor No', 'id' => 'No. Pengunjung'], 'value' => $model->visitor_no],
                    'name' => ['label' => ['en' => 'Name', 'id' => 'Nama Lengkap'], 'value' => $model->name],
                    'id_type' => ['label' => ['en' => 'ID Type', 'id' => 'Tipe Identitas'], 'value' => strtoupper((string) $model->id_type)],
                    'id_number' => ['label' => ['en' => 'ID Number', 'id' => 'Nomor Identitas'], 'value' => $model->id_number],
                    'visitor_type' => ['label' => ['en' => 'Visitor Type', 'id' => 'Tipe Pengunjung'], 'value' => $model->visitor_type instanceof VisitorType ? $model->visitor_type->value : $model->visitor_type],
                    'company' => ['label' => ['en' => 'Company', 'id' => 'Perusahaan'], 'value' => $model->company],
                    'occupation' => ['label' => ['en' => 'Occupation', 'id' => 'Jabatan'], 'value' => $model->occupation],
                    'gender' => ['label' => ['en' => 'Gender', 'id' => 'Jenis Kelamin'], 'value' => $model->gender],
                    'birth_date' => ['label' => ['en' => 'Birth Date', 'id' => 'Tanggal Lahir'], 'value' => $model->birth_date?->format('Y-m-d')],
                    'birth_place' => ['label' => ['en' => 'Birth Place', 'id' => 'Tempat Lahir'], 'value' => $model->birth_place],
                    'nationality' => ['label' => ['en' => 'Nationality', 'id' => 'Kewarganegaraan'], 'value' => $model->nationality],
                    'phone' => ['label' => ['en' => 'Phone', 'id' => 'No. Telepon'], 'value' => $model->phone],
                    'email' => ['label' => ['en' => 'Email', 'id' => 'Email'], 'value' => $model->email],
                    'address' => ['label' => ['en' => 'Address', 'id' => 'Alamat Domisili'], 'value' => $model->address],
                    'id_issued_at' => ['label' => ['en' => 'ID Issued', 'id' => 'ID Diterbitkan'], 'value' => $model->id_issued_at?->format('Y-m-d')],
                    'id_expires_at' => ['label' => ['en' => 'ID Expires', 'id' => 'Masa Berlaku ID'], 'value' => $model->id_lifetime ? 'Lifetime' : $model->id_expires_at?->format('Y-m-d')],
                    'vessel' => ['label' => ['en' => 'Vessel', 'id' => 'Kapal'], 'value' => $model->vessel?->code],
                    'certificate' => ['label' => ['en' => 'Certificate', 'id' => 'Sertifikat'], 'value' => $model->certificate],
                    'certificate_no' => ['label' => ['en' => 'Certificate No', 'id' => 'No. Sertifikat'], 'value' => $model->certificate_no],
                    'seaman_book_no' => ['label' => ['en' => 'Seaman Book No', 'id' => 'No. Buku Pelaut'], 'value' => $model->seaman_book_no],
                    'seaman_book_expires_at' => ['label' => ['en' => 'Seaman Book Expires', 'id' => 'Masa Berlaku Buku Pelaut'], 'value' => $model->seaman_book_expires_at?->format('Y-m-d')],
                    'created_at' => ['label' => ['en' => 'Created At', 'id' => 'Dibuat Pada'], 'value' => $model->created_at?->format('Y-m-d H:i:s')],
                    'updated_at' => ['label' => ['en' => 'Updated At', 'id' => 'Diperbarui Pada'], 'value' => $model->updated_at?->format('Y-m-d H:i:s')],
                ];
                break;

            case 'blacklist':
            case 'blacklisted':
                /** @var Blacklist|Visitor $model */
                $displayKtp = ($model->id_number && ! str_starts_with($model->id_number, 'NOKTP'))
                    ? $model->id_number
                    : '';

                $dateVal = $model->blacklisted_at ? $model->blacklisted_at->format('Y-m-d') : ($model->created_at ? $model->created_at->format('Y-m-d') : '');

                $fullMap = [
                    'no' => ['label' => ['en' => 'NO', 'id' => 'NO'], 'value' => $rowNo],
                    'name' => ['label' => ['en' => 'NAME OF OFFENDER', 'id' => 'NAMA PELANGGAR'], 'value' => $model->name],
                    'badge_code' => ['label' => ['en' => 'BADGE / H.I.D CODE', 'id' => 'KODE BADGE / H.I.D'], 'value' => $model->badge_code ?? ''],
                    'id_number' => ['label' => ['en' => 'KTP NO', 'id' => 'NO KTP'], 'value' => $displayKtp],
                    'offences' => ['label' => ['en' => 'OFFENCES', 'id' => 'PELANGGARAN'], 'value' => $model->offences ?? $model->blacklist_reason ?? ''],
                    'date' => ['label' => ['en' => 'DATE', 'id' => 'TANGGAL'], 'value' => $dateVal],
                    'remark' => ['label' => ['en' => 'REMARK', 'id' => 'KETERANGAN'], 'value' => $model->remark ?? ''],
                    'company' => ['label' => ['en' => 'COMPANY NAME', 'id' => 'NAMA PERUSAHAAN'], 'value' => $model->company ?? ''],
                ];
                break;

            case 'cards':
            case 'card':
                /** @var VisitorCard $model */
                $fullMap = [
                    'card_no' => ['label' => ['en' => 'Card No', 'id' => 'Nomor Kartu'], 'value' => $model->card_no],
                    'absenteeism_no' => ['label' => ['en' => 'Absenteeism No', 'id' => 'No. Absensi'], 'value' => $model->absenteeism_no],
                    'card_type' => ['label' => ['en' => 'Card Type', 'id' => 'Tipe Kartu'], 'value' => $model->card_type instanceof CardType ? $model->card_type->value : $model->card_type],
                    'note' => ['label' => ['en' => 'Note', 'id' => 'Catatan'], 'value' => $model->note],
                    'is_active' => ['label' => ['en' => 'Active', 'id' => 'Status Aktif'], 'value' => $model->is_active ? ($lang === 'en' ? 'Yes' : 'Ya') : ($lang === 'en' ? 'No' : 'Tidak')],
                    'status' => ['label' => ['en' => 'Status', 'id' => 'Status'], 'value' => $model->status],
                    'created_at' => ['label' => ['en' => 'Created At', 'id' => 'Dibuat Pada'], 'value' => $model->created_at?->format('Y-m-d H:i:s')],
                    'updated_at' => ['label' => ['en' => 'Updated At', 'id' => 'Diperbarui Pada'], 'value' => $model->updated_at?->format('Y-m-d H:i:s')],
                ];
                break;

            case 'vehicle_cards':
            case 'vehicle-cards':
            case 'vehicle_card':
                /** @var VehicleCard $model */
                $fullMap = [
                    'card_no' => ['label' => ['en' => 'Card No', 'id' => 'Nomor Kartu'], 'value' => $model->card_no],
                    'status' => ['label' => ['en' => 'Status', 'id' => 'Status'], 'value' => $model->status],
                    'created_at' => ['label' => ['en' => 'Created At', 'id' => 'Dibuat Pada'], 'value' => $model->created_at?->format('Y-m-d H:i:s')],
                    'updated_at' => ['label' => ['en' => 'Updated At', 'id' => 'Diperbarui Pada'], 'value' => $model->updated_at?->format('Y-m-d H:i:s')],
                ];
                break;

            case 'staffs':
            case 'staff':
                /** @var Staff $model */
                $fullMap = [
                    'staff_id' => ['label' => ['en' => 'Staff ID', 'id' => 'NIK / ID Staf'], 'value' => $model->staff_id],
                    'name' => ['label' => ['en' => 'Name', 'id' => 'Nama Staf'], 'value' => $model->name],
                    'department' => ['label' => ['en' => 'Department', 'id' => 'Departemen'], 'value' => $model->department],
                    'company' => ['label' => ['en' => 'Company', 'id' => 'Perusahaan'], 'value' => $model->company],
                    'occupation' => ['label' => ['en' => 'Occupation', 'id' => 'Jabatan'], 'value' => $model->occupation],
                    'status' => ['label' => ['en' => 'Status', 'id' => 'Status'], 'value' => $model->is_active ? 'Active' : 'Inactive'],
                    'gender' => ['label' => ['en' => 'Gender', 'id' => 'Jenis Kelamin'], 'value' => $model->gender],
                    'birth_date' => ['label' => ['en' => 'Birth Date', 'id' => 'Tanggal Lahir'], 'value' => $model->birth_date?->format('Y-m-d')],
                    'address' => ['label' => ['en' => 'Address', 'id' => 'Alamat'], 'value' => $model->address],
                    'created_at' => ['label' => ['en' => 'Created At', 'id' => 'Dibuat Pada'], 'value' => $model->created_at?->format('Y-m-d H:i:s')],
                    'updated_at' => ['label' => ['en' => 'Updated At', 'id' => 'Diperbarui Pada'], 'value' => $model->updated_at?->format('Y-m-d H:i:s')],
                ];
                break;

            case 'locations':
            case 'location':
                /** @var Location $model */
                $fullMap = [
                    'name' => ['label' => ['en' => 'Name', 'id' => 'Nama Lokasi / Kapal'], 'value' => $model->name],
                    'code' => ['label' => ['en' => 'Code / IMO', 'id' => 'Kode / IMO'], 'value' => $model->code],
                    'type' => ['label' => ['en' => 'Type', 'id' => 'Tipe'], 'value' => $model->type instanceof LocationType ? $model->type->value : $model->type],
                    'pic' => ['label' => ['en' => 'PIC', 'id' => 'Penanggung Jawab'], 'value' => $model->pic],
                    'call_sign' => ['label' => ['en' => 'Call Sign', 'id' => 'Call Sign'], 'value' => $model->call_sign],
                    'flag' => ['label' => ['en' => 'Flag', 'id' => 'Bendera'], 'value' => $model->flag],
                    'port' => ['label' => ['en' => 'Port', 'id' => 'Pelabuhan Asal'], 'value' => $model->port],
                    'captain' => ['label' => ['en' => 'Captain', 'id' => 'Kapten'], 'value' => $model->captain],
                    'local_agent_company' => ['label' => ['en' => 'Agent Company', 'id' => 'Perusahaan Agen'], 'value' => $model->local_agent_company],
                    'local_agent_name' => ['label' => ['en' => 'Agent PIC', 'id' => 'PIC Agen'], 'value' => $model->local_agent_name],
                    'local_agent_phone' => ['label' => ['en' => 'Agent Phone', 'id' => 'Telepon Agen'], 'value' => $model->local_agent_phone],
                    'created_at' => ['label' => ['en' => 'Created At', 'id' => 'Dibuat Pada'], 'value' => $model->created_at?->format('Y-m-d H:i:s')],
                    'updated_at' => ['label' => ['en' => 'Updated At', 'id' => 'Diperbarui Pada'], 'value' => $model->updated_at?->format('Y-m-d H:i:s')],
                ];
                break;

            default:
                return [];
        }

        // Determine which keys to export
        $keys = $selectedColumns ?: array_keys($fullMap);

        foreach ($keys as $k) {
            if (isset($fullMap[$k])) {
                $header = $fullMap[$k]['label'][$lang] ?? $fullMap[$k]['label']['en'] ?? $k;
                $val = $fullMap[$k]['value'];
                $data[$header] = self::escapeFormula($val);
            }
        }

        return $data;
    }
}
