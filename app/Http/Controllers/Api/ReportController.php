<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VisitResource;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrew;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visit;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spatie\SimpleExcel\SimpleExcelWriter;

class ReportController extends Controller
{
    public function visits(Request $request): JsonResponse
    {
        $query = $this->buildVisitsQuery($request);

        if ($request->boolean('print') || $request->input('limit') === 'print') {
            $visits = $query->get();

            return response()->json(
                $visits->map(fn (Visit $visit): array => (new VisitResource($visit))->resolve())->all()
            );
        }

        $perPage = max(1, min((int) $request->input('per_page', 100), 100));
        $visits = $query->paginate($perPage);

        return response()->json(
            $visits->through(fn (Visit $visit): array => (new VisitResource($visit))->resolve())
        );
    }

    public function exportVisits(Request $request)
    {
        $query = $this->buildVisitsQuery($request);

        return response()->streamDownload(function () use ($query) {
            $writer = SimpleExcelWriter::streamDownload('visitor-report.xlsx');

            $query->chunk(500, function ($visits) use ($writer) {
                foreach ($visits as $visit) {
                    $cards = $visit->visitorCards->pluck('card_no')->join(', ');
                    if (empty($cards) && $visit->visitorCard) {
                        $cards = $visit->visitorCard->card_no;
                    }

                    $checkInFull = $visit->date && $visit->time_in
                        ? Carbon::parse($visit->date.' '.$visit->time_in)->format('d/m/Y H:i:s')
                        : ($visit->created_at ? $visit->created_at->format('d/m/Y H:i:s') : '-');

                    $checkOutFull = $visit->checked_out_at
                        ? Carbon::parse($visit->checked_out_at)->format('d/m/Y H:i:s')
                        : ($visit->time_out ? Carbon::parse(($visit->date ?? now()->toDateString()).' '.$visit->time_out)->format('d/m/Y H:i:s') : 'OPEN');

                    $writer->addRow([
                        'Ticket No' => $visit->ticket_number ?? $visit->legacy_registration_no ?? '-',
                        'Date' => $visit->date ? Carbon::parse($visit->date)->format('d/m/Y') : '-',
                        'Check-In' => $checkInFull,
                        'Check-Out' => $checkOutFull,
                        'Time In' => $visit->time_in ? Carbon::parse($visit->time_in)->format('H:i:s') : '-',
                        'Time Out' => $visit->time_out ? Carbon::parse($visit->time_out)->format('H:i:s') : '-',
                        'Days Open' => $visit->days_open,
                        'ID Type' => strtoupper($visit->visitor?->id_type ?? '-'),
                        'ID Number' => $visit->visitor?->id_number ?? '-',
                        'Visitor Name' => $visit->visitor?->name ?? '-',
                        'Company' => $visit->visitor_company ?: ($visit->visitor?->company ?? '-'),
                        'Staff To Visit' => $visit->host_name ?: ($visit->staff?->name ?? '-'),
                        'Staff Occupation' => $visit->staff?->occupation ?? '-',
                        'Staff Department' => $visit->staff?->department ?? '-',
                        'Location' => $visit->location_name ?: ($visit->location?->name ?? '-'),
                        'Purpose' => $visit->purpose ?? '-',
                        'Visitor Cards' => $cards ?: '-',
                        'Vehicle Card' => $visit->vehicleCard?->card_no ?? '-',
                        'Vehicle No' => $visit->vehicle_no ?? '-',
                    ]);
                }
            });
        }, 'visitor-report.xlsx');
    }

    public function exportVisitsPdf(Request $request)
    {
        $query = $this->buildVisitsQuery($request);

        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $visits = $query->get();

        $startDate = $request->input('date_from', $request->input('start_date'));
        $endDate = $request->input('date_to', $request->input('end_date'));
        $printedBy = auth()->user()?->name ?? 'System';

        $pdf = Pdf::loadView('pdf.visitor-report', compact('visits', 'startDate', 'endDate', 'printedBy'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('visitor-report.pdf');
    }

    public function buildVisitsQuery(Request $request): Builder
    {
        $query = Visit::with(['visitor', 'staff', 'location', 'visitorCards', 'vehicleCard']);

        // Date range
        $dateFrom = $request->input('date_from', $request->input('start_date'));
        $dateTo = $request->input('date_to', $request->input('end_date'));

        if (! empty($dateFrom)) {
            $query->where('date', '>=', $dateFrom);
        }
        if (! empty($dateTo)) {
            $query->where('date', '<=', $dateTo);
        }

        // Time range
        if ($request->filled('time_from')) {
            $query->where('time_in', '>=', $request->input('time_from'));
        }
        if ($request->filled('time_to')) {
            $query->where('time_in', '<=', $request->input('time_to'));
        }

        // Visitor number range
        if ($request->filled('visitor_no_from')) {
            $from = (int) $request->input('visitor_no_from');
            $query->whereHas('visitor', fn ($q) => $q->where('visitor_no', '>=', $from));
        }
        if ($request->filled('visitor_no_to')) {
            $to = (int) $request->input('visitor_no_to');
            $query->whereHas('visitor', fn ($q) => $q->where('visitor_no', '<=', $to));
        }

        // Company
        if ($request->filled('company')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('company')), '%_\\')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(COALESCE(visitor_company, \'\')) LIKE ?', [$term])
                    ->orWhereHas('visitor', fn ($vq) => $vq->whereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$term]));
            });
        }

        // Visitor name
        if ($request->filled('visitor_name')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('visitor_name')), '%_\\')).'%';
            $query->whereHas('visitor', fn ($vq) => $vq->whereRaw('LOWER(name) LIKE ?', [$term]));
        }

        // Occupation
        if ($request->filled('occupation')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('occupation')), '%_\\')).'%';
            $query->whereHas('visitor', fn ($vq) => $vq->whereRaw('LOWER(COALESCE(occupation, \'\')) LIKE ?', [$term]));
        }

        // Visitor type
        if ($request->filled('visitor_type')) {
            $query->whereHas('visitor', fn ($vq) => $vq->where('visitor_type', $request->input('visitor_type')));
        } else {
            // Exclude crew visits from visitor report by default
            $query->whereDoesntHave('visitor', fn ($vq) => $vq->where('visitor_type', 'crew'));
        }

        // Staff
        if ($request->filled('staff')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('staff')), '%_\\')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(COALESCE(host_name, \'\')) LIKE ?', [$term])
                    ->orWhereHas('staff', fn ($sq) => $sq->whereRaw('LOWER(name) LIKE ?', [$term]));
            });
        }

        // Location
        if ($request->filled('location')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('location')), '%_\\')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(COALESCE(location_name, \'\')) LIKE ?', [$term])
                    ->orWhereHas('location', fn ($lq) => $lq->whereRaw('LOWER(name) LIKE ?', [$term]));
            });
        }

        // Vehicle no
        if ($request->filled('vehicle_no')) {
            $rawVal = trim((string) $request->input('vehicle_no'));
            $term = '%'.strtolower(addcslashes($rawVal, '%_\\')).'%';
            $normPlate = Visit::normalizePlate($rawVal);
            $query->where(function ($q) use ($term, $normPlate) {
                $q->whereRaw('LOWER(COALESCE(vehicle_no, \'\')) LIKE ?', [$term]);
                if ($normPlate !== null) {
                    $q->orWhere('plate_normalized', 'LIKE', '%'.$normPlate.'%');
                }
            });
        }

        // Location code or vehicle no
        if ($request->filled('location_code_or_vehicle_no')) {
            $rawVal = trim((string) $request->input('location_code_or_vehicle_no'));
            $term = '%'.strtolower(addcslashes($rawVal, '%_\\')).'%';
            $normPlate = Visit::normalizePlate($rawVal);
            $query->where(function ($q) use ($term, $normPlate) {
                $q->whereRaw('LOWER(COALESCE(vehicle_no, \'\')) LIKE ?', [$term])
                    ->orWhereHas('location', fn ($lq) => $lq->whereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$term]));
                if ($normPlate !== null) {
                    $q->orWhere('plate_normalized', 'LIKE', '%'.$normPlate.'%');
                }
            });
        }

        // Card no
        if ($request->filled('card_no')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('card_no')), '%_\\')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('visitorCards', fn ($vc) => $vc->whereRaw('LOWER(card_no) LIKE ?', [$term]))
                    ->orWhereHas('vehicleCard', fn ($vc) => $vc->whereRaw('LOWER(card_no) LIKE ?', [$term]));
            });
        }

        // Card type
        if ($request->filled('card_type')) {
            $cardType = $request->input('card_type');
            $query->whereHas('visitorCards', fn ($vc) => $vc->where('card_type', $cardType));
        }

        // Open only
        $openOnly = $request->input('open_only', $request->input('only_open'));
        if ($openOnly !== null && filter_var($openOnly, FILTER_VALIDATE_BOOLEAN)) {
            $query->whereNull('checked_out_at');
        }

        $query->orderBy('date', 'desc')
            ->orderBy('time_in', 'desc')
            ->orderBy('created_at', 'desc');

        return $query;
    }

    public function vessels(Request $request): JsonResponse
    {
        $query = $this->buildVesselsQuery($request);

        if ($request->boolean('print') || $request->input('limit') === 'print') {
            return response()->json($query->get());
        }

        $perPage = max(1, min((int) $request->input('per_page', 100), 100));

        return response()->json($query->paginate($perPage));
    }

    public function exportVessels(Request $request)
    {
        $mode = $request->input('mode', 'list_ships');
        $query = $this->buildVesselsQuery($request);

        return response()->streamDownload(function () use ($query, $mode) {
            $writer = SimpleExcelWriter::streamDownload('vessel-report.xlsx');

            $query->chunk(500, function ($arrivals) use ($writer, $mode) {
                foreach ($arrivals as $arrival) {
                    if ($mode === 'list_ships_with_crew') {
                        if ($arrival->crew->isEmpty()) {
                            $writer->addRow([
                                'IMO / Code' => $arrival->vessel?->code ?? '-',
                                'Vessel Name' => $arrival->vessel_name ?? $arrival->vessel?->name ?? '-',
                                'Captain' => $arrival->captain ?? '-',
                                'Crew Name' => '-',
                                'ID Type' => '-',
                                'ID Number' => '-',
                                'Gender' => '-',
                                'Occupation' => '-',
                                'Nationality' => '-',
                                'Certificate' => '-',
                                'Seaman Book No' => '-',
                            ]);
                        } else {
                            foreach ($arrival->crew as $crew) {
                                $writer->addRow([
                                    'IMO / Code' => $arrival->vessel?->code ?? '-',
                                    'Vessel Name' => $arrival->vessel_name ?? $arrival->vessel?->name ?? '-',
                                    'Captain' => $arrival->captain ?? '-',
                                    'Crew Name' => $crew->name ?? '-',
                                    'ID Type' => strtoupper($crew->id_type ?? '-'),
                                    'ID Number' => $crew->id_number ?? '-',
                                    'Gender' => $crew->gender ?? '-',
                                    'Occupation' => $crew->occupation ?? '-',
                                    'Nationality' => $crew->nationality ?? '-',
                                    'Certificate' => $crew->certificate ?? $crew->certificate_no ?? '-',
                                    'Seaman Book No' => $crew->seaman_book_no ?? '-',
                                ]);
                            }
                        }
                    } else {
                        $writer->addRow([
                            'IMO / Code' => $arrival->vessel?->code ?? '-',
                            'Vessel Name' => $arrival->vessel_name ?? $arrival->vessel?->name ?? '-',
                            'Sign In Date' => $arrival->sign_in_date ? Carbon::parse($arrival->sign_in_date)->format('d/m/Y') : '-',
                            'Sign In Port' => $arrival->sign_in_port ?? '-',
                            'Sign Out Date' => $arrival->sign_out_date ? Carbon::parse($arrival->sign_out_date)->format('d/m/Y') : '-',
                            'Sign Out Port' => $arrival->sign_out_port ?? '-',
                            'Total Days' => $arrival->total_days,
                            'Captain' => $arrival->captain ?? '-',
                            'Local Agent' => $arrival->local_agent_company ?? '-',
                            'Foreign Agent' => $arrival->foreign_agent_company ?? '-',
                        ]);
                    }
                }
            });
        }, 'vessel-report.xlsx');
    }

    public function exportVesselsPdf(Request $request)
    {
        $mode = $request->input('mode', 'list_ships');
        $query = $this->buildVesselsQuery($request);

        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $arrivals = $query->get();

        $startDate = $request->input('date_from', $request->input('start_date'));
        $endDate = $request->input('date_to', $request->input('end_date'));
        $printedBy = auth()->user()?->name ?? 'System';

        $pdf = Pdf::loadView('pdf.vessel-report', compact('arrivals', 'mode', 'startDate', 'endDate', 'printedBy'))
            ->setPaper('a4', 'landscape');

        $filename = $mode === 'list_ships_with_crew' ? 'vessel-crew-manifest-report.pdf' : 'vessel-report.pdf';

        return $pdf->download($filename);
    }

    public function buildVesselsQuery(Request $request): Builder
    {
        $mode = $request->input('mode', 'list_ships');
        $query = VesselArrival::query();

        if ($mode === 'list_ships_with_crew') {
            $crewStatus = $request->input('crew_status', 'all');
            $query->with(['vessel', 'crew' => function ($q) use ($request, $crewStatus) {
                $q->where('visitor_type', 'crew');
                if ($crewStatus === 'active') {
                    $q->wherePivotIn('status', ['expected', 'checked_in']);
                } elseif ($crewStatus === 'completed' || $crewStatus === 'checked_out') {
                    $q->wherePivot('status', 'checked_out');
                }
                if ($request->filled('crew_visitor_no_from')) {
                    $q->where('visitor_no', '>=', (int) $request->input('crew_visitor_no_from'));
                }
                if ($request->filled('crew_visitor_no_to')) {
                    $q->where('visitor_no', '<=', (int) $request->input('crew_visitor_no_to'));
                }
                $q->orderBy('name', 'asc');
            }]);

            if ($request->filled('crew_visitor_no_from')) {
                $from = (int) $request->input('crew_visitor_no_from');
                $query->whereHas('crew', fn ($q) => $q->where('visitor_no', '>=', $from));
            }
            if ($request->filled('crew_visitor_no_to')) {
                $to = (int) $request->input('crew_visitor_no_to');
                $query->whereHas('crew', fn ($q) => $q->where('visitor_no', '<=', $to));
            }
        } else {
            $query->with('vessel');
        }

        if ($request->filled('vessel_name')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('vessel_name')), '%_\\')).'%';
            $query->whereRaw('LOWER(vessel_name) LIKE ?', [$term]);
        }

        $timeFrom = $request->input('time_from');
        $timeTo = $request->input('time_to');
        if ($timeFrom && strlen($timeFrom) === 5) {
            $timeFrom .= ':00';
        }
        if ($timeTo && strlen($timeTo) === 5) {
            $timeTo .= ':59';
        }

        $stayStatus = $request->input('stay_status');
        if ($stayStatus === 'out') {
            $query->whereNotNull('sign_out_date');
            if ($request->filled('date_from')) {
                $query->where('sign_out_date', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('sign_out_date', '<=', $request->input('date_to'));
            }
            if ($timeFrom) {
                $query->where('sign_out_time', '>=', $timeFrom);
            }
            if ($timeTo) {
                $query->where('sign_out_time', '<=', $timeTo);
            }
        } elseif ($stayStatus === 'not_out') {
            $query->whereNull('sign_out_date');
            if ($request->filled('date_from')) {
                $query->where('sign_in_date', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('sign_in_date', '<=', $request->input('date_to'));
            }
            if ($timeFrom) {
                $query->where('sign_in_time', '>=', $timeFrom);
            }
            if ($timeTo) {
                $query->where('sign_in_time', '<=', $timeTo);
            }
        } else {
            // 'in' or 'all' or default
            if ($request->filled('date_from')) {
                $query->where('sign_in_date', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('sign_in_date', '<=', $request->input('date_to'));
            }
            if ($timeFrom) {
                $query->where('sign_in_time', '>=', $timeFrom);
            }
            if ($timeTo) {
                $query->where('sign_in_time', '<=', $timeTo);
            }
        }

        $query->orderBy('sign_in_date', 'desc')->orderBy('created_at', 'desc');

        return $query;
    }

    public function crew(Request $request): JsonResponse
    {
        $mode = $request->input('mode', 'placements');
        $query = ($mode === 'history' || $mode === 'logs')
            ? $this->buildCrewLogsQuery($request)
            : $this->buildCrewQuery($request);

        if ($request->boolean('print') || $request->input('limit') === 'print') {
            return response()->json($query->get());
        }

        $perPage = max(1, min((int) $request->input('per_page', 50), 100));

        return response()->json($query->paginate($perPage));
    }

    public function exportCrew(Request $request)
    {
        $mode = $request->input('mode', 'placements');

        if ($mode === 'history' || $mode === 'logs') {
            $query = $this->buildCrewLogsQuery($request);

            return response()->streamDownload(function () use ($query) {
                $writer = SimpleExcelWriter::streamDownload('vessel-crew-history-report.xlsx');

                $query->chunk(500, function ($logs) use ($writer) {
                    foreach ($logs as $log) {
                        $crew = $log->visitor;
                        $arrival = $log->vesselArrival;

                        $actionLabel = match ($log->action) {
                            'check_in' => 'Check-In',
                            'check_out' => 'Check-Out',
                            default => ucfirst((string) $log->action),
                        };

                        $writer->addRow([
                            'Waktu Aktivitas' => $log->logged_at ? Carbon::parse($log->logged_at)->format('d/m/Y H:i') : '-',
                            'No Kru' => $crew?->visitor_no ?? '-',
                            'Nama Kru' => $crew?->name ?? '-',
                            'Tipe ID' => strtoupper($crew?->id_type ?? '-'),
                            'Nomor ID' => $crew?->id_number ?? '-',
                            'Jenis Kelamin' => $crew?->gender ?? '-',
                            'Kebangsaan' => $crew?->nationality ?? '-',
                            'Perusahaan' => $crew?->company ?? '-',
                            'Jabatan / Occupation' => $crew?->occupation ?? '-',
                            'Buku Pelaut' => $crew?->seaman_book_no ?? '-',
                            'No Sertifikat' => $crew?->certificate_no ?: ($crew?->certificate ?? '-'),
                            'Nama Kapal Ditugaskan' => $arrival?->vessel_name ?? $arrival?->vessel?->name ?? '-',
                            'Kode / IMO Kapal' => $arrival?->vessel?->code ?? '-',
                            'Aksi / Status In-Out' => $actionLabel,
                            'Sumber Aktivitas' => $log->source ?? '-',
                            'Petugas' => $log->performed_by_name ?? '-',
                            'Catatan' => $log->notes ?? '-',
                        ]);
                    }
                });
            }, 'vessel-crew-history-report.xlsx');
        }

        $query = $this->buildCrewQuery($request);

        return response()->streamDownload(function () use ($query) {
            $writer = SimpleExcelWriter::streamDownload('vessel-crew-report.xlsx');

            $query->chunk(500, function ($placements) use ($writer) {
                foreach ($placements as $placement) {
                    $crew = $placement->visitor;
                    $arrival = $placement->vesselArrival;

                    $statusLabel = match ($placement->status) {
                        'checked_in' => 'Di Kapal (Checked-In)',
                        'checked_out' => 'Sudah Turun (Checked-Out)',
                        'expected' => 'Belum Check-In (Expected)',
                        default => ucfirst((string) $placement->status),
                    };

                    $writer->addRow([
                        'No Kru' => $crew?->visitor_no ?? '-',
                        'Nama Kru' => $crew?->name ?? '-',
                        'Tipe ID' => strtoupper($crew?->id_type ?? '-'),
                        'Nomor ID' => $crew?->id_number ?? '-',
                        'Jenis Kelamin' => $crew?->gender ?? '-',
                        'Kebangsaan' => $crew?->nationality ?? '-',
                        'Perusahaan' => $crew?->company ?? '-',
                        'Jabatan / Occupation' => $crew?->occupation ?? '-',
                        'Buku Pelaut' => $crew?->seaman_book_no ?? '-',
                        'No Sertifikat' => $crew?->certificate_no ?: ($crew?->certificate ?? '-'),
                        'Nama Kapal' => $arrival?->vessel_name ?? $arrival?->vessel?->name ?? '-',
                        'Kode / IMO Kapal' => $arrival?->vessel?->code ?? '-',
                        'Call Sign' => $arrival?->vessel?->call_sign ?? '-',
                        'Bendera' => $arrival?->vessel?->flag ?? '-',
                        'Nakhoda / Captain' => $arrival?->captain ?? '-',
                        'Status Penempatan' => $statusLabel,
                        'Waktu Check-In' => $placement->checked_in_at ? Carbon::parse($placement->checked_in_at)->format('d/m/Y H:i') : '-',
                        'Waktu Check-Out' => $placement->checked_out_at ? Carbon::parse($placement->checked_out_at)->format('d/m/Y H:i') : '-',
                        'Tgl Masuk Kapal (Sign-In)' => $arrival?->sign_in_date ? Carbon::parse($arrival->sign_in_date)->format('d/m/Y') : '-',
                        'Pelabuhan Asal' => $arrival?->sign_in_port ?? '-',
                        'Tgl Keluar Kapal (Sign-Out)' => $arrival?->sign_out_date ? Carbon::parse($arrival->sign_out_date)->format('d/m/Y') : '-',
                        'Pelabuhan Tujuan' => $arrival?->sign_out_port ?? '-',
                        'Agen Lokal' => $arrival?->local_agent_company ?? '-',
                        'Agen Luar Negeri' => $arrival?->foreign_agent_company ?? '-',
                        'Catatan' => $placement->notes ?? '-',
                    ]);
                }
            });
        }, 'vessel-crew-report.xlsx');
    }

    public function exportCrewPdf(Request $request)
    {
        $mode = $request->input('mode', 'placements');

        if ($mode === 'history' || $mode === 'logs') {
            $query = $this->buildCrewLogsQuery($request)
                ->join('visitors', 'vessel_arrival_crew_logs.visitor_id', '=', 'visitors.id')
                ->select('vessel_arrival_crew_logs.*')
                ->reorder()
                ->orderBy('visitors.name', 'asc')
                ->orderBy('vessel_arrival_crew_logs.logged_at', 'asc');

            $logs = $query->get();
            $records = $this->transformCrewLogsToSessions($logs);
        } else {
            $query = $this->buildCrewQuery($request)
                ->join('visitors', 'vessel_arrival_crew.visitor_id', '=', 'visitors.id')
                ->select('vessel_arrival_crew.*')
                ->reorder()
                ->orderBy('visitors.name', 'asc')
                ->orderBy('vessel_arrival_crew.checked_in_at', 'asc')
                ->orderBy('vessel_arrival_crew.created_at', 'asc');

            $records = $query->get();
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        $startDate = $request->input('date_from', $request->input('start_date'));
        $endDate = $request->input('date_to', $request->input('end_date'));
        $printedBy = auth()->user()?->name ?? 'System';

        $pdf = Pdf::loadView('pdf.crew-report', compact('records', 'mode', 'startDate', 'endDate', 'printedBy'))
            ->setPaper('a4', 'landscape');

        $filename = ($mode === 'history' || $mode === 'logs') ? 'vessel-crew-history-report.pdf' : 'vessel-crew-report.pdf';

        return $pdf->download($filename);
    }

    /**
     * Transform individual activity logs into paired in-out sessions per crew member.
     *
     * @param  Collection<int, VesselArrivalCrewLog>  $logs
     * @return Collection<int, object>
     */
    public function transformCrewLogsToSessions($logs)
    {
        $groupedByVisitor = $logs->groupBy('visitor_id');
        $sessions = collect();

        foreach ($groupedByVisitor as $visitorId => $visitorLogs) {
            $sortedLogs = $visitorLogs->sortBy(fn ($l) => $l->logged_at ? Carbon::parse($l->logged_at)->timestamp : 0)->values();
            $openCheckIns = [];

            foreach ($sortedLogs as $log) {
                if ($log->action === 'check_in') {
                    $openCheckIns[] = $log;
                } elseif ($log->action === 'check_out') {
                    if (! empty($openCheckIns)) {
                        $checkInLog = array_shift($openCheckIns);
                        $sessions->push((object) [
                            'visitor' => $checkInLog->visitor,
                            'vesselArrival' => $checkInLog->vesselArrival,
                            'checked_in_at' => $checkInLog->logged_at,
                            'checked_out_at' => $log->logged_at,
                            'status' => 'checked_out',
                            'performed_by_name' => $log->performed_by_name ?: $checkInLog->performed_by_name,
                            'notes' => $log->notes ?: $checkInLog->notes,
                        ]);
                    } else {
                        $sessions->push((object) [
                            'visitor' => $log->visitor,
                            'vesselArrival' => $log->vesselArrival,
                            'checked_in_at' => null,
                            'checked_out_at' => $log->logged_at,
                            'status' => 'checked_out',
                            'performed_by_name' => $log->performed_by_name,
                            'notes' => $log->notes,
                        ]);
                    }
                }
            }

            // Remaining open check-ins (currently onboard)
            foreach ($openCheckIns as $openLog) {
                $sessions->push((object) [
                    'visitor' => $openLog->visitor,
                    'vesselArrival' => $openLog->vesselArrival,
                    'checked_in_at' => $openLog->logged_at,
                    'checked_out_at' => null,
                    'status' => 'checked_in',
                    'performed_by_name' => $openLog->performed_by_name,
                    'notes' => $openLog->notes,
                ]);
            }
        }

        return $sessions->sort(function ($a, $b) {
            $nameA = strtolower(trim((string) ($a->visitor?->name ?? '')));
            $nameB = strtolower(trim((string) ($b->visitor?->name ?? '')));
            $cmpName = strcmp($nameA, $nameB);
            if ($cmpName !== 0) {
                return $cmpName;
            }

            $timeA = $a->checked_in_at ? Carbon::parse($a->checked_in_at)->timestamp : ($a->checked_out_at ? Carbon::parse($a->checked_out_at)->timestamp : 0);
            $timeB = $b->checked_in_at ? Carbon::parse($b->checked_in_at)->timestamp : ($b->checked_out_at ? Carbon::parse($b->checked_out_at)->timestamp : 0);

            return $timeA <=> $timeB;
        })->values();
    }

    public function buildCrewQuery(Request $request): Builder
    {
        $query = VesselArrivalCrew::query()
            ->with([
                'vesselArrival.vessel',
                'visitor',
            ]);

        // Search across crew name, id_number, seaman_book_no, certificate_no, company, or vessel_name
        if ($request->filled('search')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('search')), '%_\\')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('visitor', function ($vq) use ($term) {
                    $vq->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(id_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(seaman_book_no, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(certificate_no, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$term]);
                })->orWhereHas('vesselArrival', function ($aq) use ($term) {
                    $aq->whereRaw('LOWER(vessel_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(captain, \'\')) LIKE ?', [$term]);
                });
            });
        }

        // Vessel filter (name or id)
        if ($request->filled('vessel_name')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('vessel_name')), '%_\\')).'%';
            $query->whereHas('vesselArrival', fn ($aq) => $aq->whereRaw('LOWER(vessel_name) LIKE ?', [$term]));
        }
        if ($request->filled('vessel_arrival_id')) {
            $query->where('vessel_arrival_id', $request->input('vessel_arrival_id'));
        }

        // Status filter: expected, checked_in, checked_out
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->where('status', $request->input('status'));
        }

        // Nationality
        if ($request->filled('nationality')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('nationality')), '%_\\')).'%';
            $query->whereHas('visitor', fn ($vq) => $vq->whereRaw('LOWER(COALESCE(nationality, \'\')) LIKE ?', [$term]));
        }

        // Company
        if ($request->filled('company')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('company')), '%_\\')).'%';
            $query->whereHas('visitor', fn ($vq) => $vq->whereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$term]));
        }

        // Date range (applies to checked_in_at or vessel_arrival sign_in_date)
        if ($request->filled('date_from')) {
            $query->where(function ($q) use ($request) {
                $q->whereDate('checked_in_at', '>=', $request->input('date_from'))
                    ->orWhereHas('vesselArrival', fn ($aq) => $aq->where('sign_in_date', '>=', $request->input('date_from')));
            });
        }
        if ($request->filled('date_to')) {
            $query->where(function ($q) use ($request) {
                $q->whereDate('checked_in_at', '<=', $request->input('date_to'))
                    ->orWhereHas('vesselArrival', fn ($aq) => $aq->where('sign_in_date', '<=', $request->input('date_to')));
            });
        }

        // Time range (applies to checked_in_at or vessel_arrival sign_in_time)
        if ($request->filled('time_from')) {
            $timeFrom = $request->input('time_from');
            $query->where(function ($q) use ($timeFrom) {
                $q->whereTime('checked_in_at', '>=', $timeFrom)
                    ->orWhere(function ($sub) use ($timeFrom) {
                        $sub->whereNull('checked_in_at')
                            ->whereHas('vesselArrival', fn ($aq) => $aq->where('sign_in_time', '>=', $timeFrom));
                    });
            });
        }
        if ($request->filled('time_to')) {
            $timeTo = $request->input('time_to');
            $query->where(function ($q) use ($timeTo) {
                $q->whereTime('checked_in_at', '<=', $timeTo)
                    ->orWhere(function ($sub) use ($timeTo) {
                        $sub->whereNull('checked_in_at')
                            ->whereHas('vesselArrival', fn ($aq) => $aq->where('sign_in_time', '<=', $timeTo));
                    });
            });
        }

        // Ordering: active status first, then newest created_at
        $query->orderByRaw("CASE WHEN status = 'checked_in' THEN 1 WHEN status = 'expected' THEN 2 ELSE 3 END")
            ->orderBy('created_at', 'desc');

        return $query;
    }

    public function buildCrewLogsQuery(Request $request): Builder
    {
        $query = VesselArrivalCrewLog::query()
            ->with([
                'vesselArrival.vessel',
                'visitor',
                'user',
            ]);

        // Search across crew name, id_number, seaman_book_no, certificate_no, company, or vessel_name
        if ($request->filled('search')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('search')), '%_\\')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereHas('visitor', function ($vq) use ($term) {
                    $vq->whereRaw('LOWER(name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(id_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(seaman_book_no, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(certificate_no, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$term]);
                })->orWhereHas('vesselArrival', function ($aq) use ($term) {
                    $aq->whereRaw('LOWER(vessel_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(captain, \'\')) LIKE ?', [$term]);
                });
            });
        }

        // Vessel filter (name or arrival id)
        if ($request->filled('vessel_name')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('vessel_name')), '%_\\')).'%';
            $query->whereHas('vesselArrival', fn ($aq) => $aq->whereRaw('LOWER(vessel_name) LIKE ?', [$term]));
        }
        if ($request->filled('vessel_arrival_id')) {
            $query->where('vessel_arrival_id', $request->input('vessel_arrival_id'));
        }

        // Action filter: check_in, check_out, etc.
        if ($request->filled('action') && $request->input('action') !== 'all') {
            $query->where('action', $request->input('action'));
        }

        // Nationality
        if ($request->filled('nationality')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('nationality')), '%_\\')).'%';
            $query->whereHas('visitor', fn ($vq) => $vq->whereRaw('LOWER(COALESCE(nationality, \'\')) LIKE ?', [$term]));
        }

        // Company
        if ($request->filled('company')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('company')), '%_\\')).'%';
            $query->whereHas('visitor', fn ($vq) => $vq->whereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$term]));
        }

        // Date range (logged_at)
        if ($request->filled('date_from')) {
            $query->whereDate('logged_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('logged_at', '<=', $request->input('date_to'));
        }

        // Time range (logged_at)
        if ($request->filled('time_from')) {
            $query->whereTime('logged_at', '>=', $request->input('time_from'));
        }
        if ($request->filled('time_to')) {
            $query->whereTime('logged_at', '<=', $request->input('time_to'));
        }

        $query->orderBy('logged_at', 'desc')->orderBy('id', 'desc');

        return $query;
    }
}
