<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VesselArrivalRequest;
use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VesselArrivalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));
        $query = VesselArrival::with('vessel')->withCount('crew');

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
            ->get();

        return response()->json($candidates);
    }

    public function store(VesselArrivalRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $arrival = DB::transaction(function () use ($validated) {
            $location = Location::find($validated['location_id']);
            $validated['vessel_name'] = $validated['vessel_name'] ?? $location?->name;

            $arrival = VesselArrival::create($validated);

            if (array_key_exists('crew_ids', $validated) && is_array($validated['crew_ids'])) {
                $arrival->crew()->sync($validated['crew_ids']);
            }

            return $arrival;
        });

        $arrival->load(['vessel', 'crew']);

        return response()->json($arrival, 201);
    }

    public function show(string $id): JsonResponse
    {
        $arrival = VesselArrival::with(['vessel', 'crew'])->findOrFail($id);

        return response()->json($arrival);
    }

    public function update(VesselArrivalRequest $request, string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);
        $validated = $request->validated();

        DB::transaction(function () use ($arrival, $validated) {
            if (array_key_exists('location_id', $validated)) {
                $arrival->location_id = $validated['location_id'];
                $arrival->vessel_name = Location::find($validated['location_id'])?->name;
            }

            $fields = [
                'sign_in_date', 'sign_out_date', 'sign_in_port', 'sign_out_port', 'captain',
                'local_agent_company', 'local_agent_name', 'local_agent_phone', 'local_agent_location',
                'foreign_agent_company', 'foreign_agent_name', 'foreign_agent_phone', 'foreign_agent_location',
            ];

            foreach ($fields as $field) {
                if (array_key_exists($field, $validated)) {
                    $arrival->{$field} = $validated[$field];
                }
            }

            if (array_key_exists('crew_ids', $validated)) {
                $arrival->crew()->sync($validated['crew_ids'] ?? []);
            }

            $arrival->save();
        });

        $arrival->load(['vessel', 'crew']);

        return response()->json($arrival);
    }

    public function destroy(string $id): JsonResponse
    {
        $arrival = VesselArrival::findOrFail($id);
        $arrival->delete();

        return response()->json(['message' => 'Vessel arrival deleted successfully']);
    }
}
