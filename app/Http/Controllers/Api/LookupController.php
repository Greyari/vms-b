<?php

namespace App\Http\Controllers\Api;

use App\Enums\CardType;
use App\Http\Controllers\Controller;
use App\Models\Nationality;
use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        return response()->json(
            Visit::query()
                ->where('date', '>=', now()->subDays(90)->toDateString())
                ->whereNotNull('purpose')
                ->where('purpose', '!=', '')
                ->groupBy('purpose')
                ->orderByRaw('COUNT(*) DESC')
                ->orderBy('purpose')
                ->limit(self::LIMIT)
                ->pluck('purpose')
        );
    }

    public function cardTypes(): JsonResponse
    {
        return response()->json(array_map(
            fn (CardType $type): array => ['value' => $type->value, 'label' => $type->label()],
            CardType::cases(),
        ));
    }
}
