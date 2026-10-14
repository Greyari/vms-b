<?php

namespace App\Http\Controllers\Api;

use App\Enums\CardType;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Nationality;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Suggestions for form fields. Any signed-in user may read them: they hold no
 * personal data, only the names the forms offer as you type.
 */
class LookupController extends Controller
{
    /** How many suggestions a text field receives at most. */
    private const LIMIT = 20;

    public function nationalities(): JsonResponse
    {
        return response()->json(Nationality::query()->orderBy('name')->pluck('name'));
    }

    public function companies(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        if ($search === '') {
            return response()->json([]);
        }

        // Postgres LIKE is case-sensitive: compare lower-cased, wildcards escaped.
        $prefix = strtolower(addcslashes($search, '%_\\')).'%';

        return response()->json(
            Visitor::query()
                ->whereNotNull('company')
                ->whereRaw('LOWER(company) LIKE ?', [$prefix])
                ->distinct()
                ->orderBy('company')
                ->limit(self::LIMIT)
                ->pluck('company')
        );
    }

    public function purposes(): JsonResponse
    {
        $purposes = Cache::remember('lookup_visit_purposes', 300, function () {
            return Visit::query()
                ->where('date', '>=', now()->subDays(90)->toDateString())
                ->whereNotNull('purpose')
                ->where('purpose', '!=', '')
                ->groupBy('purpose')
                ->orderByRaw('COUNT(*) DESC')
                ->orderBy('purpose')
                ->limit(self::LIMIT)
                ->pluck('purpose')
                ->values();
        });

        return response()->json($purposes);
    }

    public function visitFormData(): JsonResponse
    {
        $locations = Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->select(['id', 'name', 'code', 'type', 'is_active'])
            ->get();

        $staffs = Staff::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->select(['id', 'name', 'department', 'is_active'])
            ->get();

        $cards = VisitorCard::query()
            ->where('is_active', true)
            ->where('status', 'available')
            ->orderBy('card_no')
            ->select(['id', 'card_no', 'status', 'card_type'])
            ->get();

        $vehicleCards = VehicleCard::query()
            ->where('status', 'available')
            ->orderBy('card_no')
            ->select(['id', 'card_no', 'status'])
            ->get();

        $purposes = Cache::remember('lookup_visit_purposes', 300, function () {
            return Visit::query()
                ->where('date', '>=', now()->subDays(90)->toDateString())
                ->whereNotNull('purpose')
                ->where('purpose', '!=', '')
                ->groupBy('purpose')
                ->orderByRaw('COUNT(*) DESC')
                ->orderBy('purpose')
                ->limit(self::LIMIT)
                ->pluck('purpose')
                ->values();
        });

        return response()->json([
            'locations' => $locations,
            'staffs' => $staffs,
            'cards' => $cards,
            'vehicle_cards' => $vehicleCards,
            'purposes' => $purposes,
        ]);
    }

    public function cardTypes(): JsonResponse
    {
        return response()->json(array_map(
            fn (CardType $type): array => ['value' => $type->value, 'label' => $type->label()],
            CardType::cases(),
        ));
    }
}
