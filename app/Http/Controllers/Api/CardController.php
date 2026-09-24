<?php

namespace App\Http\Controllers\Api;

use App\Enums\CardType;
use App\Http\Controllers\Controller;
use App\Models\VisitorCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

class CardController extends Controller
{
    /** Validation messages the create and the edit form share. */
    private const MESSAGES = [
        'status.prohibited' => 'Card status is set by check-in and check-out, not by hand.',
    ];

    public function downloadTemplate()
    {
        return response()->streamDownload(function () {
            $writer = SimpleExcelWriter::streamDownload('card_template.xlsx');
            $writer->addRow(['card_no' => 'CARD-001']);
        }, 'card_template.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv',
        ]);

        try {
            $file = $request->file('file');
            $extension = $file->getClientOriginalExtension();

            $rows = SimpleExcelReader::create($file->getRealPath(), $extension)->getRows();

            $count = 0;
            $rows->each(function (array $rowProperties) use (&$count) {
                if (! array_key_exists('card_no', $rowProperties)) {
                    throw new \Exception('Invalid formatting');
                }

                // Status belongs to check-in and check-out: a new card starts
                // available and an existing card keeps the status it has.
                VisitorCard::firstOrCreate(
                    ['card_no' => (string) $rowProperties['card_no']],
                    ['status' => 'available'],
                );
                $count++;
            });

            return response()->json(['message' => "Successfully imported {$count} cards."]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Import failed. Please make sure the format is correct.'], 400);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        return response()->json($this->filteredCards($request)->orderBy('card_no')->paginate($perPage));
    }

    public function export(Request $request)
    {
        $cards = $this->filteredCards($request)->orderBy('card_no');

        return response()->streamDownload(function () use ($cards) {
            $writer = SimpleExcelWriter::streamDownload('cards.xlsx');
            $cards->lazy(1000)->each(function (VisitorCard $card) use ($writer): void {
                $writer->addRow([
                    'Card No' => $card->card_no,
                    'Absenteeism No' => $card->absenteeism_no,
                    'Card Type' => $card->card_type?->value,
                    'Note' => $card->note,
                    'Active' => $card->is_active ? 'Yes' : 'No',
                    'Status' => $card->status,
                ]);
            });
        }, 'cards.xlsx');
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->cardRules(), self::MESSAGES);

        $card = VisitorCard::create([...$validated, 'status' => 'available']);

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
     * on the card or absenteeism number; a card-number range compared as
     * lower-cased text, as VMS-old did, in byte order on PostgreSQL so the
     * database's language rules do not reorder dashes and letters; the card
     * type; the active flag; and the status.
     */
    private function filteredCards(Request $request): Builder
    {
        $request->validate([
            'card_type' => ['nullable', Rule::enum(CardType::class)],
            'is_active' => ['nullable', Rule::in(['0', '1', 'true', 'false'])],
            'status' => ['nullable', Rule::in(['available', 'in_use'])],
        ]);

        $query = VisitorCard::query();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $term = '%'.strtolower(addcslashes($search, '%_\\')).'%';
            $query->where(fn (Builder $q) => $q->whereRaw('LOWER(card_no) LIKE ?', [$term])
                ->orWhereRaw('LOWER(absenteeism_no) LIKE ?', [$term]));
        }

        $collate = DB::connection()->getDriverName() === 'pgsql' ? ' COLLATE "C"' : '';
        foreach (['from' => '>=', 'to' => '<='] as $bound => $operator) {
            $value = trim((string) $request->input($bound, ''));
            if ($value !== '') {
                $query->whereRaw("LOWER(card_no){$collate} {$operator} ?", [strtolower($value)]);
            }
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
     * The fields the card master may set. `status` is not one of them:
     * check-in and check-out keep it in step with the open visits.
     *
     * @return array<string, mixed>
     */
    private function cardRules(?VisitorCard $card = null): array
    {
        return [
            'card_no' => ['required', 'string', 'max:255', Rule::unique('visitor_cards', 'card_no')->ignore($card?->id)],
            'absenteeism_no' => ['nullable', 'string', 'max:255'],
            'card_type' => ['nullable', Rule::enum(CardType::class)],
            'note' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['prohibited'],
        ];
    }
}
