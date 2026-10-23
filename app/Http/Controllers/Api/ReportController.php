<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VisitResource;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrew;
use App\Models\Visit;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\SimpleExcel\SimpleExcelWriter;

class ReportController extends Controller
{
    public function visits(Request $request): JsonResponse
    {
        $query = $this->buildVisitsQuery($request);

        if ($request->boolean('print') || $request->input('limit') === 'print') {
            $count = (clone $query)->count();
            if ($count > 5000) {
                return response()->json([
                    'message' => 'Print and PDF export limit of 5,000 rows exceeded. Please narrow your date range or filters.',
                ], 422);
            }

            $visits = $query->take(5000)->get();

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

                    $writer->addRow([
                        'Ticket No' => $visit->ticket_number ?? $visit->legacy_registration_no ?? '-',
                        'Date' => $visit->date,
                        'Time In' => $visit->time_in ? Carbon::parse($visit->time_in)->format('H:i') : '-',
                        'Time Out' => $visit->time_out ? Carbon::parse($visit->time_out)->format('H:i') : '-',
                        'Days Open' => $visit->days_open,
                        'ID Type' => strtoupper($visit->visitor?->id_type ?? '-'),
                        'ID Number' => $visit->visitor?->id_number ?? '-',
                        'Visitor Name' => $visit->visitor?->name ?? '-',
                        'Company' => $visit->visitor_company ?: ($visit->visitor?->company ?? '-'),
                        'Staff To Visit' => $visit->host_name ?: ($visit->staff?->name ?? '-'),
                        'Location' => $visit->location_name ?: ($visit->location?->name ?? '-'),
                        'Location Code' => $visit->location?->code ?? '-',
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

        $count = (clone $query)->count();
        if ($count > 5000) {
            return response()->json([
                'message' => 'Print and PDF export limit of 5,000 rows exceeded. Please narrow your date range or filters.',
            ], 422);
        }

        $visits = $query->take(5000)->get();

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

        // Location code or vehicle no
        if ($request->filled('location_code_or_vehicle_no')) {
            $term = '%'.strtolower(addcslashes(trim((string) $request->input('location_code_or_vehicle_no')), '%_\\')).'%';
            $query->where(function ($q) use ($term) {
                $q->whereRaw('LOWER(COALESCE(vehicle_no, \'\')) LIKE ?', [$term])
                    ->orWhereHas('location', fn ($lq) => $lq->whereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$term]));
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
            $count = (clone $query)->count();
            if ($count > 5000) {
                return response()->json([
                    'message' => 'Print and PDF export limit of 5,000 rows exceeded. Please narrow your date range or filters.',
                ], 422);
            }

            return response()->json($query->take(5000)->get());
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
                                'Sign In Date' => $arrival->sign_in_date ? Carbon::parse($arrival->sign_in_date)->format('d/m/Y') : '-',
                                'Sign In Port' => $arrival->sign_in_port ?? '-',
                                'Sign Out Date' => $arrival->sign_out_date ? Carbon::parse($arrival->sign_out_date)->format('d/m/Y') : '-',
                                'Sign Out Port' => $arrival->sign_out_port ?? '-',
                                'Total Days' => $arrival->total_days,
                                'Captain' => $arrival->captain ?? '-',
                                'Crew No' => '-',
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
                                    'Sign In Date' => $arrival->sign_in_date ? Carbon::parse($arrival->sign_in_date)->format('d/m/Y') : '-',
                                    'Sign In Port' => $arrival->sign_in_port ?? '-',
                                    'Sign Out Date' => $arrival->sign_out_date ? Carbon::parse($arrival->sign_out_date)->format('d/m/Y') : '-',
                                    'Sign Out Port' => $arrival->sign_out_port ?? '-',
                                    'Total Days' => $arrival->total_days,
                                    'Captain' => $arrival->captain ?? '-',
                                    'Crew No' => $crew->visitor_no ?? '-',
                                    'Crew Name' => $crew->name ?? '-',
                                    'ID Type' => strtoupper($crew->id_type ?? '-'),
                                    'ID Number' => $crew->id_number ?? '-',
                                    'Gender' => $crew->gender ?? '-',
                                    'Occupation' => $crew->occupation ?? '-',
                                    'Nationality' => $crew->nationality ?? '-',
                                    'Certificate' => $crew->certificate ?? '-',
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

        $count = (clone $query)->count();
        if ($count > 5000) {
            return response()->json([
                'message' => 'Print and PDF export limit of 5,000 rows exceeded. Please narrow your date range or filters.',
            ], 422);
        }

        $arrivals = $query->take(5000)->get();

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
            $query->with(['vessel', 'crew' => function ($q) use ($request) {
                $q->where('visitor_type', 'crew');
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

        $stayStatus = $request->input('stay_status');
        if ($stayStatus === 'out') {
            $query->whereNotNull('sign_out_date');
            if ($request->filled('date_from')) {
                $query->where('sign_out_date', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('sign_out_date', '<=', $request->input('date_to'));
            }
        } elseif ($stayStatus === 'not_out') {
            $query->whereNull('sign_out_date');
            if ($request->filled('date_from')) {
                $query->where('sign_in_date', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('sign_in_date', '<=', $request->input('date_to'));
            }
        } else {
            // 'in' or 'all' or default
            if ($request->filled('date_from')) {
                $query->where('sign_in_date', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('sign_in_date', '<=', $request->input('date_to'));
            }
        }

        $query->orderBy('sign_in_date', 'desc')->orderBy('created_at', 'desc');

        return $query;
    }

    public function crew(Request $request): JsonResponse
    {
        $query = $this->buildCrewQuery($request);

        if ($request->boolean('print') || $request->input('limit') === 'print') {
            $count = (clone $query)->count();
            if ($count > 5000) {
                return response()->json([
                    'message' => 'Print and export limit of 5,000 rows exceeded. Please narrow your date range or filters.',
                ], 422);
            }

            return response()->json($query->take(5000)->get());
        }

        $perPage = max(1, min((int) $request->input('per_page', 50), 100));

        return response()->json($query->paginate($perPage));
    }

    public function exportCrew(Request $request)
    {
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
        $query = $this->buildCrewQuery($request);

        $count = (clone $query)->count();
        if ($count > 5000) {
            return response()->json([
                'message' => 'Print and PDF export limit of 5,000 rows exceeded. Please narrow your date range or filters.',
            ], 422);
        }

        $placements = $query->take(5000)->get();

        $startDate = $request->input('date_from', $request->input('start_date'));
        $endDate = $request->input('date_to', $request->input('end_date'));
        $printedBy = auth()->user()?->name ?? 'System';

        $pdf = Pdf::loadView('pdf.crew-report', compact('placements', 'startDate', 'endDate', 'printedBy'))
            ->setPaper('a4', 'landscape');

        return $pdf->download('vessel-crew-report.pdf');
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

        // Ordering: active status first, then newest created_at
        $query->orderByRaw("CASE WHEN status = 'checked_in' THEN 1 WHEN status = 'expected' THEN 2 ELSE 3 END")
            ->orderBy('created_at', 'desc');

        return $query;
    }
}
