<?php

namespace App\Http\Controllers\Api;

use App\Enums\CardType;
use App\Http\Controllers\Controller;
use App\Models\VisitorCard;
use App\Services\MasterExportService;
use App\Services\MasterImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CardController extends Controller
{
    /** Validation messages the create and the edit form share. */
    private const MESSAGES = [
        'status.prohibited' => 'Card status is set by check-in and check-out, not by hand.',
        'status.not_in' => 'Card status is set by check-in and check-out, not by hand.',
    ];

    public function downloadTemplate(MasterImportService $importService)
    {
        return $importService->downloadTemplate(MasterImportService::TYPE_CARD);
    }

    public function importPreview(Request $request, MasterImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                        $fail('The file field must be a file of type: xlsx, xls, csv.');
                    }
                },
            ],
        ]);

        $result = $importService->preview(MasterImportService::TYPE_CARD, $request->file('file'));

        return response()->json($result);
    }

    public function import(Request $request, MasterImportService $importService): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:10240',
                function ($attribute, $value, $fail) {
                    $ext = strtolower($value->getClientOriginalExtension());
                    if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                        $fail('The file field must be a file of type: xlsx, xls, csv.');
                    }
                },
            ],
            'duplicate_action' => 'nullable|string|in:update,replace',
        ]);

        $duplicateAction = $request->input('duplicate_action', 'update');

        try {
            $result = $importService->import(MasterImportService::TYPE_CARD, $request->file('file'), $duplicateAction);

            return response()->json($result);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Import failed. '.$e->getMessage()], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $requestedPerPage = (int) $request->input('per_page', 100);
        $perPage = (! $request->has('page') && $requestedPerPage >= 1000)
            ? max($requestedPerPage, 100000)
            : max(1, min($requestedPerPage, 100000));

        return response()->json($this->filteredCards($request)->orderBy('card_no')->paginate($perPage));
    }

    public function export(Request $request, MasterExportService $exportService)
    {
        return $exportService->export('cards', $request);
    }

    public function exportCount(Request $request, MasterExportService $exportService): JsonResponse
    {
        return response()->json(['count' => $exportService->count('cards', $request)]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->cardRules(), self::MESSAGES);

        $status = $validated['status'] ?? 'available';
        $idCardNo = $validated['id_card_no'] ?? ($validated['absenteeism_no'] ?? null);

        $cardType = $validated['card_type'] ?? null;
        if ((! $cardType || $cardType === 'visitor') && ! empty($validated['name'])) {
            $lowerName = strtolower($validated['name']);
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
            }
        }
        if ($cardType) {
            $validated['card_type'] = $cardType;
        }

        $card = VisitorCard::create([
            ...$validated,
            'id_card_no' => $idCardNo,
            'absenteeism_no' => $idCardNo,
            'status' => $status,
        ]);

        return response()->json($card->refresh(), 201);
    }

    public function show($id)
    {
        $card = VisitorCard::findOrFail($id);

        return response()->json($card);
    }

    public function update(Request $request, $id)
    {
        $card = VisitorCard::findOrFail($id);
        $validated = $request->validate($this->cardRules($card), self::MESSAGES);

        if ($card->status === 'in_use' && isset($validated['status']) && $validated['status'] !== 'in_use') {
            return response()->json([
                'message' => 'This card is in use by an open visit. Check the visit out first.',
            ], 422);
        }

        if (array_key_exists('id_card_no', $validated) && ! array_key_exists('absenteeism_no', $validated)) {
            $validated['absenteeism_no'] = $validated['id_card_no'];
        }

        if ((empty($validated['card_type']) || $validated['card_type'] === 'visitor') && ! empty($validated['name'])) {
            $lowerName = strtolower($validated['name']);
            if (str_contains($lowerName, 'green')) {
                $validated['card_type'] = 'visitor_green';
            } elseif (str_contains($lowerName, 'white')) {
                $validated['card_type'] = 'visitor_white';
            } elseif (str_contains($lowerName, 'crew')) {
                $validated['card_type'] = 'crew';
            } elseif (str_contains($lowerName, 'temp')) {
                $validated['card_type'] = 'temporary_worker';
            } elseif (str_contains($lowerName, 'special')) {
                $validated['card_type'] = 'special';
            }
        }

        $card->update($validated);

        return response()->json($card);
    }

    public function destroy($id)
    {
        $card = VisitorCard::findOrFail($id);

        // An open visit holds this card; deleting it would leave that visit
        // pointing at a card that is gone from the master.
        if ($card->status === 'in_use') {
            return response()->json([
                'message' => 'This card is in use by an open visit. Check the visit out first.',
            ], 422);
        }

        $card->delete();

        return response()->json(['message' => 'Card deleted successfully']);
    }

    public function trashed()
    {
        return response()->json(VisitorCard::onlyTrashed()->get());
    }

    public function restore($id)
    {
        $card = VisitorCard::onlyTrashed()->findOrFail($id);
        $card->restore();

        return response()->json(['message' => 'Card restored successfully']);
    }

    /**
     * Cards matching the master screen's filters: a case-insensitive search
     * on the card, id_card_no, name or absenteeism number; a card-number range compared as
     * lower-cased text, as VMS-old did, in byte order on PostgreSQL so the
     * database's language rules do not reorder dashes and letters; the card
     * type; the active flag; and the status.
     */
    private function filteredCards(Request $request): Builder
    {
        $request->validate([
            'card_type' => ['nullable', Rule::enum(CardType::class)],
            'is_active' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'status' => ['nullable', Rule::in(['available', 'in_use', 'lost', 'damaged'])],
        ]);

        $query = VisitorCard::query();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(card_no) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(id_card_no, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(name, \'\')) LIKE ?', [$term])
                ->orWhereRaw('LOWER(COALESCE(absenteeism_no, \'\')) LIKE ?', [$term]));
        }

        $collate = DB::connection()->getDriverName() === 'pgsql' ? ' COLLATE "C"' : '';

        $from = $request->input('from') ?? $request->input('card_no_from');
        if ($from !== null && trim((string) $from) !== '') {
            $query->whereRaw("LOWER(card_no){$collate} >= ?", [strtolower(trim((string) $from))]);
        }

        $to = $request->input('to') ?? $request->input('card_no_to');
        if ($to !== null && trim((string) $to) !== '') {
            $query->whereRaw("LOWER(card_no){$collate} <= ?", [strtolower(trim((string) $to))]);
        }

        if ($request->filled('card_type')) {
            $query->where('card_type', $request->input('card_type'));
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return $query;
    }

    /**
     * The fields the card master may set.
     *
     * @return array<string, mixed>
     */
    private function cardRules(?VisitorCard $card = null): array
    {
        return [
            'card_no' => ['required', 'string', 'max:255', Rule::unique('visitor_cards', 'card_no')->ignore($card?->id)],
            'id_card_no' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'date_issued' => ['nullable', 'date'],
            'absenteeism_no' => ['nullable', 'string', 'max:255'],
            'card_type' => ['nullable', Rule::enum(CardType::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'nullable', Rule::notIn(['in_use']), Rule::in(['available', 'lost', 'damaged'])],
        ];
    }
}
