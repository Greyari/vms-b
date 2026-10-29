<?php

namespace App\Http\Controllers\Api;

use App\Enums\LocationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\VesselArrivalRequest;
use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrew;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visitor;
use App\Services\VesselCrewService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\SimpleExcel\SimpleExcelWriter;

class VesselArrivalController extends Controller
{
    public function __construct(
        protected VesselCrewService $crewService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->input('per_page', 100), 1000));
        $query = VesselArrival::with('vessel')->withCount([
            'crew',
            'crew as checked_in_crew_count' => fn ($q) => $q->where('vessel_arrival_crew.status', 'checked_in'),
            'crew as active_crew_count' => fn ($q) => $q->whereIn('vessel_arrival_crew.status', ['expected', 'checked_in']),
            'crew as completed_crew_count' => fn ($q) => $q->where('vessel_arrival_crew.status', 'checked_out'),
        ]);

        if ($request->filled('vessel_name')) {
            $search = trim((string) $request->input('vessel_name'));
            $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
            $query->whereRaw('LOWER(vessel_name) LIKE ?', [$term]);
        }

        if ($request->filled('location_id')) {
            $query->where('location_id', $request->input('location_id'));
        }

        if ($request->filled('date_from')) {
            $query->where('sign_in_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->where('sign_in_date', '<=', $request->input('date_to'));
        }

        if ($request->filled('stay_status')) {
            $status = $request->input('stay_status');
            if ($status === 'out') {
                $query->whereNotNull('sign_out_date');
            } elseif ($status === 'not_out') {
                $query->whereNull('sign_out_date');
            }
        }

        $query->orderBy('sign_in_date', 'desc')->orderBy('created_at', 'desc');

        return response()->json($query->paginate($perPage));
    }

    /**
     * Unified search for Vessels and Crew.
     * Searches crew by name, ID number, company, seaman book, occupation.
     * Searches vessels by vessel name, code (IMO No), call sign, captain.
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) ($request->input('q') ?? $request->input('search') ?? ''));
        if (mb_strlen($q) < 2) {
            return response()->json([
                'vessels' => [],
                'crew' => [],
            ]);
        }

        $term = '%'.strtolower(addcslashes($q, '%_\\')).'%';

        // 1. Search Vessels (Kedatangan Kapal)
        $vesselsQuery = VesselArrival::with('vessel')
            ->withCount([
                'crew as active_crew_count' => fn ($sub) => $sub->whereIn('vessel_arrival_crew.status', ['expected', 'checked_in']),
                'crew as completed_crew_count' => fn ($sub) => $sub->where('vessel_arrival_crew.status', 'checked_out'),
            ])
            ->where(function ($query) use ($term) {
                $query->whereRaw('LOWER(vessel_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(captain, \'\')) LIKE ?', [$term])
                    ->orWhereHas('vessel', function ($locQuery) use ($term) {
                        $locQuery->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(code, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(call_sign, \'\')) LIKE ?', [$term]);
                    });
            });

        // Optional page filters
        if ($request->filled('location_id')) {
            $vesselsQuery->where('location_id', $request->input('location_id'));
        }
        if ($request->filled('stay_status')) {
            $status = $request->input('stay_status');
            if ($status === 'out') {
                $vesselsQuery->whereNotNull('sign_out_date');
            } elseif ($status === 'not_out') {
                $vesselsQuery->whereNull('sign_out_date');
            }
        }

        $vessels = $vesselsQuery
            ->orderBy('sign_in_date', 'desc')
            ->limit(20)
            ->get()
            ->map(function (VesselArrival $arrival) {
                return [
                    'id' => $arrival->id,
                    'vessel_name' => $arrival->vessel_name,
                    'location_id' => $arrival->location_id,
                    'imo_no' => $arrival->vessel?->code,
                    'call_sign' => $arrival->vessel?->call_sign,
                    'captain' => $arrival->captain,
                    'sign_in_date' => $arrival->sign_in_date,
                    'sign_out_date' => $arrival->sign_out_date,
                    'is_in_port' => $arrival->sign_out_date === null,
                    'active_crew_count' => $arrival->active_crew_count,
                    'completed_crew_count' => $arrival->completed_crew_count,
                ];
            });

        // 2. Search Crew
        // Matches in vessel_arrival_crew + visitors + vessel_arrivals
        $crewQuery = DB::table('vessel_arrival_crew')
            ->join('visitors', 'vessel_arrival_crew.visitor_id', '=', 'visitors.id')
            ->join('vessel_arrivals', 'vessel_arrival_crew.vessel_arrival_id', '=', 'vessel_arrivals.id')
            ->leftJoin('locations', 'vessel_arrivals.location_id', '=', 'locations.id')
            ->select([
                'visitors.id as visitor_id',
                'visitors.name as name',
                'visitors.id_type as id_type',
                'visitors.id_number as id_number',
                'visitors.company as company',
                'visitors.occupation as occupation',
                'visitors.phone as phone',
                'visitors.is_blacklisted as is_blacklisted',
                'vessel_arrival_crew.id as pivot_id',
                'vessel_arrival_crew.vessel_arrival_id as vessel_arrival_id',
                'vessel_arrival_crew.status as status',
                'vessel_arrival_crew.checked_in_at as checked_in_at',
                'vessel_arrival_crew.checked_out_at as checked_out_at',
                'vessel_arrival_crew.notes as notes',
                'vessel_arrivals.vessel_name as vessel_name',
                'vessel_arrivals.sign_in_date as sign_in_date',
                'vessel_arrivals.sign_out_date as sign_out_date',
                'locations.code as vessel_code',
            ])
            ->where(function ($sub) use ($term) {
                $sub->whereRaw('LOWER(visitors.name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(visitors.id_number, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(visitors.seaman_book_no, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(visitors.company, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(visitors.occupation, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(vessel_arrivals.vessel_name, \'\')) LIKE ?', [$term]);
            });

        // Order active status first (expected / checked_in), then newest check-in date, and deduplicate by visitor_id
        // so a person only appears once with their current or latest placement.
        $crewResults = $crewQuery
            ->orderByRaw("CASE WHEN vessel_arrival_crew.status IN ('expected', 'checked_in') THEN 0 ELSE 1 END")
            ->orderBy('vessel_arrival_crew.checked_in_at', 'desc')
            ->orderBy('visitors.name', 'asc')
            ->limit(100)
            ->get()
            ->unique('visitor_id')
            ->take(30)
            ->values()
            ->map(function ($row) {
                // Partially mask ID number for privacy (e.g. 217103******0004)
                $rawId = (string) $row->id_number;
                $len = mb_strlen($rawId);
                if ($len >= 8) {
                    $masked = mb_substr($rawId, 0, 4).str_repeat('*', max(4, $len - 8)).mb_substr($rawId, -4);
                } elseif ($len > 4) {
                    $masked = mb_substr($rawId, 0, 2).str_repeat('*', $len - 4).mb_substr($rawId, -2);
                } else {
                    $masked = $rawId;
                }

                return [
                    'visitor_id' => $row->visitor_id,
                    'pivot_id' => $row->pivot_id,
                    'vessel_arrival_id' => $row->vessel_arrival_id,
                    'name' => $row->name,
                    'id_type' => $row->id_type,
                    'id_number_masked' => $masked,
                    'company' => $row->company,
                    'occupation' => $row->occupation,
                    'phone' => $row->phone,
                    'is_blacklisted' => (bool) $row->is_blacklisted,
                    'status' => $row->status,
                    'checked_in_at' => $row->checked_in_at,
                    'checked_out_at' => $row->checked_out_at,
                    'notes' => $row->notes,
                    'vessel_name' => $row->vessel_name,
                    'sign_in_date' => $row->sign_in_date,
                    'sign_out_date' => $row->sign_out_date,
                    'is_active_placement' => in_array($row->status, ['expected', 'checked_in'], true),
                ];
            });

        return response()->json([
            'vessels' => $vessels,
            'crew' => $crewResults,
        ]);
    }

    /**
     * Get detail of a specific crew member for the Person Panel drawer/modal.
     */
    public function crewPlacementDetail(string $visitorId): JsonResponse
    {
        $visitor = Visitor::with('vessel')->findOrFail($visitorId);

        // Current / latest placement
        $currentPivot = VesselArrivalCrew::where('visitor_id', $visitorId)
            ->orderByRaw("CASE WHEN status IN ('expected', 'checked_in') THEN 0 ELSE 1 END")
            ->orderBy('created_at', 'desc')
            ->first();

        $currentArrival = $currentPivot
            ? VesselArrival::with('vessel')->find($currentPivot->vessel_arrival_id)
            : null;

        // Timeline history logs
        $logs = VesselArrivalCrewLog::where('visitor_id', $visitorId)
            ->join('vessel_arrivals', 'vessel_arrival_crew_logs.vessel_arrival_id', '=', 'vessel_arrivals.id')
            ->select([
                'vessel_arrival_crew_logs.*',
                'vessel_arrivals.vessel_name as vessel_name',
            ])
            ->orderBy('logged_at', 'desc')
            ->orderBy('vessel_arrival_crew_logs.created_at', 'desc')
            ->limit(20)
            ->get();

        return response()->json([
            'visitor' => [
                'id' => $visitor->id,
                'name' => $visitor->name,
                'visitor_no' => $visitor->visitor_no,
                'id_type' => $visitor->id_type,
                'id_number' => $visitor->id_number,
                'company' => $visitor->company,
                'occupation' => $visitor->occupation,
                'phone' => $visitor->phone,
                'gender' => $visitor->gender,
                'nationality' => $visitor->nationality,
                'is_blacklisted' => (bool) $visitor->is_blacklisted,
                'blacklist_reason' => $visitor->blacklist_reason,
            ],
            'current_placement' => $currentPivot ? [
                'vessel_arrival_id' => $currentArrival?->id,
                'vessel_name' => $currentArrival?->vessel_name,
                'location_id' => $currentArrival?->location_id,
                'status' => $currentPivot->status,
                'checked_in_at' => $currentPivot->checked_in_at,
                'checked_out_at' => $currentPivot->checked_out_at,
                'notes' => $currentPivot->notes,
                'sign_in_date' => $currentArrival?->sign_in_date,
                'sign_out_date' => $currentArrival?->sign_out_date,
            ] : null,
            'logs' => $logs,
        ]);
    }

    public function crewCandidates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
            'vessel_arrival_id' => ['nullable', 'uuid', 'exists:vessel_arrivals,id'],
            'search' => ['nullable', 'string', 'max:255'],
            'all' => ['nullable', 'boolean'],
        ]);

        $locationId = $validated['location_id'];
        $arrivalId = $validated['vessel_arrival_id'] ?? null;
        $search = trim((string) ($request->input('search') ?? $request->input('q') ?? ''));
        $showAll = $request->boolean('all');

        $columns = [
            'id', 'visitor_no', 'name', 'id_type', 'id_number', 'company',
            'gender', 'occupation', 'nationality', 'certificate',
            'certificate_no', 'seaman_book_no', 'is_blacklisted', 'blacklist_reason',
        ];

        $candidatesQuery = Visitor::query()
            ->select($columns)
            ->where('visitor_type', 'crew');

        if (! $showAll && $search === '') {
            $candidatesQuery->where(function ($query) use ($locationId, $arrivalId) {
                $query->where('vessel_id', $locationId)
                    ->orWhereNull('vessel_id');

                if ($arrivalId !== null) {
                    $query->orWhereHas('vesselArrivals', function ($subQuery) use ($arrivalId) {
                        $subQuery->where('vessel_arrivals.id', $arrivalId);
                    });
                }
            });
        }

        if ($search !== '') {
            $term = '%'.strtolower($search).'%';
            $candidatesQuery->where(function ($sub) use ($term) {
                $sub->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(id_number) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(company, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(seaman_book_no, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(occupation, \'\')) LIKE ?', [$term]);
            });
        }

        $candidates = $candidatesQuery
            ->distinct()
            ->orderBy('name', 'asc')
            ->limit(300)
            ->get();

        // Attach active placement information to each candidate
        $candidateIds = $candidates->pluck('id')->all();
        $activePlacements = DB::table('vessel_arrival_crew')
            ->join('vessel_arrivals', 'vessel_arrival_crew.vessel_arrival_id', '=', 'vessel_arrivals.id')
            ->whereIn('vessel_arrival_crew.visitor_id', $candidateIds)
            ->whereIn('vessel_arrival_crew.status', ['expected', 'checked_in'])
            ->select([
                'vessel_arrival_crew.visitor_id',
                'vessel_arrival_crew.vessel_arrival_id',
                'vessel_arrival_crew.status',
                'vessel_arrivals.vessel_name',
            ])
            ->get()
            ->keyBy('visitor_id');

        $result = $candidates->map(function ($c) use ($activePlacements) {
            $data = $c->toArray();
            $active = $activePlacements->get($c->id);
            $data['active_placement'] = $active ? [
                'vessel_arrival_id' => $active->vessel_arrival_id,
                'vessel_name' => $active->vessel_name,
                'status' => $active->status,
            ] : null;

            return $data;
        });

        return response()->json($result);
    }

    public function store(VesselArrivalRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $location = Location::find($validated['location_id']);
        if (! empty($validated['sign_out_date']) && $this->isStayingVessel($location)) {
            throw ValidationException::withMessages([
                'sign_out_date' => ['Kapal tipe Tugboat dan Barge berstatus operasional menetap (Stay) dan tidak dapat di-check-out.'],
            ]);
        }

        $arrival = DB::transaction(function () use ($validated, $user, $location) {
            $validated['vessel_name'] = $validated['vessel_name'] ?? $location?->name;

            if (empty($validated['captain']) && ! empty($location?->captain)) {
                $validated['captain'] = $location->captain;
            } elseif (! empty($validated['captain']) && $location) {
                $location->update(['captain' => $validated['captain']]);
            }

            $arrival = VesselArrival::create($validated);

            if (array_key_exists('crew_ids', $validated) && is_array($validated['crew_ids'])) {
                $crewIds = array_values(array_unique(array_filter($validated['crew_ids'])));
                if (! empty($crewIds)) {
                    $this->crewService->checkIn($arrival, $crewIds, null, 'Terdaftar saat pembuatan kedatangan kapal', $user, true);
                }
            }

            return $arrival;
        });

        $arrival->load(['vessel', 'crew', 'crewLogs.visitor']);

        return response()->json($arrival, 201);
    }

    public function show(string $id): JsonResponse
    {
        $arrival = VesselArrival::with(['vessel', 'crew', 'crewLogs.visitor'])->findOrFail($id);

        return response()->json($arrival);
    }

    public function crewLogs(Request $request, string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);

        $query = VesselArrivalCrewLog::with(['visitor', 'visit'])
            ->where('vessel_arrival_id', $arrival->id)
            ->orderBy('logged_at', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('visitor_id')) {
            $query->where('visitor_id', $request->query('visitor_id'));
        }

        if ($request->filled('action') && in_array($request->query('action'), ['check_in', 'check_out'], true)) {
            $query->where('action', $request->query('action'));
        }

        return response()->json($query->get());
    }

    public function exportCrewLogs(Request $request, string $id)
    {
        $arrival = VesselArrival::with('vessel')->findOrFail($id);

        $query = VesselArrivalCrewLog::with(['visitor', 'visit'])
            ->where('vessel_arrival_id', $arrival->id)
            ->orderBy('logged_at', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('visitor_id')) {
            $query->where('visitor_id', $request->query('visitor_id'));
        }

        if ($request->filled('action') && in_array($request->query('action'), ['check_in', 'check_out'], true)) {
            $query->where('action', $request->query('action'));
        }

        $cleanVessel = Str::slug($arrival->vessel_name ?: ($arrival->vessel?->name ?: 'kapal'));
        $fileName = "log-kru-{$cleanVessel}-".now()->format('YmdHis').'.xlsx';

        return response()->streamDownload(function () use ($query) {
            $writer = SimpleExcelWriter::streamDownload('log-kru.xlsx');

            $query->chunk(500, function ($logs) use ($writer) {
                foreach ($logs as $log) {
                    $visitor = $log->visitor;
                    $actionLabel = match ($log->action) {
                        'check_in' => 'Check-In',
                        'check_out' => 'Check-Out',
                        default => ucfirst((string) $log->action),
                    };

                    $writer->addRow([
                        'Waktu Aktivitas' => $log->logged_at ? Carbon::parse($log->logged_at)->format('d/m/Y H:i') : '-',
                        'Nama Kru' => $visitor?->name ?? '-',
                        'No Kru' => $visitor?->visitor_no ?? '-',
                        'Tipe ID' => strtoupper($visitor?->id_type ?? '-'),
                        'Nomor ID' => $visitor?->id_number ?? '-',
                        'Jabatan' => $visitor?->occupation ?? '-',
                        'Kebangsaan' => $visitor?->nationality ?? '-',
                        'Perusahaan / Agen' => $visitor?->company ?? '-',
                        'Buku Pelaut' => $visitor?->seaman_book_no ?? '-',
                        'No Sertifikat' => $visitor?->certificate_no ?: ($visitor?->certificate ?? '-'),
                        'Aksi' => $actionLabel,
                        'Sumber' => $log->source ?? '-',
                        'Petugas' => $log->performed_by_name ?? '-',
                        'Catatan' => $log->notes ?? '-',
                    ]);
                }
            });
        }, $fileName);
    }

    public function update(VesselArrivalRequest $request, string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);
        $validated = $request->validated();
        $user = $request->user();

        $targetLocationId = $validated['location_id'] ?? $arrival->location_id;
        $targetLocation = Location::find($targetLocationId);
        if (! empty($validated['sign_out_date']) && $this->isStayingVessel($targetLocation)) {
            throw ValidationException::withMessages([
                'sign_out_date' => ['Kapal tipe Tugboat dan Barge berstatus operasional menetap (Stay) dan tidak dapat di-check-out.'],
            ]);
        }

        DB::transaction(function () use ($arrival, $validated, $user) {
            $wasInPort = $arrival->sign_out_date === null;
            $existingCrewIds = $arrival->crew()->pluck('visitors.id')->all();

            if (array_key_exists('location_id', $validated)) {
                $arrival->location_id = $validated['location_id'];
                $arrival->vessel_name = Location::find($validated['location_id'])?->name;
            }

            $fields = [
                'sign_in_date', 'sign_in_time', 'sign_out_date', 'sign_out_time', 'sign_in_port', 'sign_out_port', 'captain',
                'local_agent_company', 'local_agent_name', 'local_agent_phone', 'local_agent_location',
                'foreign_agent_company', 'foreign_agent_name', 'foreign_agent_phone', 'foreign_agent_location',
            ];

            foreach ($fields as $field) {
                if (array_key_exists($field, $validated)) {
                    $arrival->{$field} = $validated[$field];
                }
            }

            if (! empty($validated['captain']) && $arrival->location_id) {
                Location::where('id', $arrival->location_id)->update(['captain' => $validated['captain']]);
            }

            if (array_key_exists('crew_ids', $validated)) {
                $newCrewList = array_values(array_unique(array_filter($validated['crew_ids'] ?? [])));
                $toRemove = array_diff($existingCrewIds, $newCrewList);
                $toAdd = array_diff($newCrewList, $existingCrewIds);
                $now = now();

                if (! empty($toRemove)) {
                    foreach ($toRemove as $crewId) {
                        VesselArrivalCrewLog::create([
                            'vessel_arrival_id' => $arrival->id,
                            'visitor_id' => $crewId,
                            'action' => 'check_out',
                            'source' => 'vessel_signon',
                            'performed_by' => $user?->id,
                            'performed_by_name' => $user?->name ?? $user?->username,
                            'notes' => 'Dilepas dari manifest kedatangan kapal',
                            'logged_at' => $now,
                        ]);
                    }
                    $arrival->crew()->detach($toRemove);
                }

                if (! empty($toAdd)) {
                    $this->crewService->checkIn($arrival, $toAdd, null, 'Ditambahkan pada manifest kedatangan kapal', $user, true);
                }

                if (! empty($newCrewList)) {
                    Visitor::whereIn('id', $newCrewList)
                        ->update(['vessel_id' => $arrival->location_id]);
                }
            }

            $arrival->save();

            // If vessel just signed out, automatically check out any remaining checked_in crew
            if ($wasInPort && $arrival->sign_out_date !== null) {
                $now = now();
                $checkedInVisitorIds = DB::table('vessel_arrival_crew')
                    ->where('vessel_arrival_id', $arrival->id)
                    ->whereIn('status', ['expected', 'checked_in'])
                    ->pluck('visitor_id')
                    ->all();

                if (! empty($checkedInVisitorIds)) {
                    $this->crewService->checkOut(
                        $arrival,
                        $checkedInVisitorIds,
                        $now->toIso8601String(),
                        'Otomatis Check-Out saat kapal berangkat (Sign-Out)',
                        $user
                    );
                }
            }
        });

        $arrival->load(['vessel', 'crew', 'crewLogs.visitor']);

        return response()->json($arrival);
    }

    public function checkInCrew(Request $request, string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'visitor_id' => ['nullable', 'uuid', 'exists:visitors,id', 'required_without_all:visitor_ids,crew_ids'],
            'visitor_ids' => ['nullable', 'array', 'min:1', 'required_without_all:visitor_id,crew_ids'],
            'visitor_ids.*' => ['uuid', 'exists:visitors,id'],
            'crew_ids' => ['nullable', 'array', 'min:1', 'required_without_all:visitor_id,visitor_ids'],
            'crew_ids.*' => ['uuid', 'exists:visitors,id'],
            'checked_in_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $ids = ! empty($validated['crew_ids'])
            ? $validated['crew_ids']
            : (! empty($validated['visitor_ids']) ? $validated['visitor_ids'] : [$validated['visitor_id']]);

        $this->crewService->checkIn(
            $arrival,
            $ids,
            $validated['checked_in_at'] ?? null,
            $validated['notes'] ?? null,
            $user,
            $request->boolean('allow_transfer', false)
        );

        $arrival->load(['vessel', 'crew', 'crewLogs.visitor']);

        return response()->json($arrival);
    }

    public function checkOutCrew(Request $request, string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'visitor_id' => ['nullable', 'uuid', 'exists:visitors,id', 'required_without_all:visitor_ids,crew_ids'],
            'visitor_ids' => ['nullable', 'array', 'min:1', 'required_without_all:visitor_id,crew_ids'],
            'visitor_ids.*' => ['uuid', 'exists:visitors,id'],
            'crew_ids' => ['nullable', 'array', 'min:1', 'required_without_all:visitor_id,visitor_ids'],
            'crew_ids.*' => ['uuid', 'exists:visitors,id'],
            'checked_out_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $ids = ! empty($validated['crew_ids'])
            ? $validated['crew_ids']
            : (! empty($validated['visitor_ids']) ? $validated['visitor_ids'] : [$validated['visitor_id']]);

        $this->crewService->checkOut(
            $arrival,
            $ids,
            $validated['checked_out_at'] ?? null,
            $validated['notes'] ?? null,
            $user
        );

        $arrival->load(['vessel', 'crew', 'crewLogs.visitor']);

        return response()->json($arrival);
    }

    public function reactivateCrew(Request $request, string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'visitor_id' => ['required', 'uuid', 'exists:visitors,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->crewService->reactivate(
            $arrival,
            $validated['visitor_id'],
            $validated['notes'] ?? null,
            $user
        );

        $arrival->load(['vessel', 'crew', 'crewLogs.visitor']);

        return response()->json($arrival);
    }

    public function detachCrew(Request $request, string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'visitor_id' => ['required', 'uuid', 'exists:visitors,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->crewService->detach(
            $arrival,
            $validated['visitor_id'],
            $validated['notes'] ?? null,
            $user
        );

        $arrival->load(['vessel', 'crew', 'crewLogs.visitor']);

        return response()->json($arrival);
    }

    public function transferCrew(Request $request, string $id): JsonResponse
    {
        $targetArrival = VesselArrival::findOrFail($id);
        $user = $request->user();

        $validated = $request->validate([
            'visitor_id' => ['required', 'uuid', 'exists:visitors,id'],
            'source_arrival_id' => ['nullable', 'uuid', 'exists:vessel_arrivals,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $this->crewService->transfer(
            $validated['visitor_id'],
            $targetArrival->id,
            $validated['source_arrival_id'] ?? null,
            $validated['notes'] ?? null,
            $user
        );

        $targetArrival->load(['vessel', 'crew', 'crewLogs.visitor']);

        return response()->json($targetArrival);
    }

    public function destroy(string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);
        $arrival->delete();

        return response()->json(['message' => 'Vessel arrival deleted successfully']);
    }

    protected function isStayingVessel(?Location $location): bool
    {
        if (! $location) {
            return false;
        }

        return in_array($location->type, [LocationType::TugBoat, LocationType::Barge], true)
            || in_array($location->vessel_type, ['tug_boat', 'barge'], true);
    }
}
