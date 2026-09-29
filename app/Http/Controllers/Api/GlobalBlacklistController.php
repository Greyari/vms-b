<?php

namespace App\Http\Controllers\Api;

use App\Enums\BlacklistReasonCategory;
use App\Http\Controllers\Controller;
use App\Models\GlobalBlacklist;
use App\Models\GlobalBlacklistOutbox;
use App\Models\Visitor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The Global tab of the Blacklist page (hybrid spec §6.3, decisions PE-5, FE-4b, SEC-3, BE-4).
 *
 * Promoting and revoking never call the hub directly: the request is queued in the outbox (logged with
 * who asked and the local note) and the sync command sends it, so it survives an internet outage. The
 * mirror itself changes only when the hub's answer comes back in a later pull.
 */
class GlobalBlacklistController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(['active', 'revoked', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = GlobalBlacklist::query()->orderByDesc('sync_seq');
        match ($filters['status'] ?? 'active') {
            'active' => $query->where('is_active', true),
            'revoked' => $query->where('is_active', false),
            'all' => null,
        };

        if (filled($filters['search'] ?? null)) {
            $term = trim($filters['search']);
            $name = '%'.strtolower(addcslashes($term, '%_\\')).'%';
            $number = '%'.addcslashes(strtoupper((string) preg_replace('/[\s\-]+/u', '', $term)), '%_\\').'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$name])->orWhere('id_number', 'like', $number));
        }

        $yard = (string) config('vms.sync.yard_code');

        return response()->json(
            $query->paginate((int) ($filters['per_page'] ?? 50))->through(fn (GlobalBlacklist $entry): array => [
                'id' => $entry->id,
                'id_type' => $entry->id_type,
                'id_number' => $entry->id_number,
                'name' => $entry->name,
                'reason_category' => $entry->reason_category->value,
                'reason_label' => $entry->reason_category->label(),
                'registered_by_yard' => $entry->registered_by_yard,
                'is_active' => $entry->is_active,
                'can_revoke' => $entry->is_active && $yard !== '' && $entry->registered_by_yard === $yard,
                'updated_at' => $entry->updated_at?->format('Y-m-d H:i:s'),
            ])
        );
    }

    public function outbox(): JsonResponse
    {
        $rows = GlobalBlacklistOutbox::with('requester:id,name')->latest()->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (GlobalBlacklistOutbox $row): array => [
            'id' => $row->id,
            'action' => $row->action,
            'id_type' => $row->id_type,
            'id_number' => $row->id_number,
            'name' => $row->name,
            'reason_category' => $row->reason_category?->value,
            'reason_label' => $row->reason_category?->label(),
            'local_note' => $row->local_note,
            'requested_by' => $row->requester?->name,
            'status' => $row->sent_at === null ? 'pending' : ($row->last_error === null ? 'sent' : 'rejected'),
            'attempts' => $row->attempts,
            'last_error' => $row->last_error,
            'created_at' => $row->created_at?->format('Y-m-d H:i:s'),
            'sent_at' => $row->sent_at?->format('Y-m-d H:i:s'),
        ])->all()]);
    }

    /** "Naikkan ke global": only a visitor already on this yard's blacklist (PE-5). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'visitor_id' => ['required', 'uuid', 'exists:visitors,id'],
            'reason_category' => ['required', Rule::enum(BlacklistReasonCategory::class)],
            'local_note' => ['required', 'string', 'max:1000'],
        ]);

        $visitor = Visitor::findOrFail($data['visitor_id']);
        if (! $visitor->is_blacklisted) {
            throw ValidationException::withMessages(['visitor_id' => 'Only a visitor on this yard\'s blacklist can be promoted to the global blacklist.']);
        }
        if (GlobalBlacklist::activeForNumber($visitor->id_number)->where('id_type', $visitor->id_type)->exists()) {
            throw ValidationException::withMessages(['visitor_id' => 'This number is already on the global blacklist.']);
        }
        if ($this->alreadyQueued('add', $visitor->id_type, $visitor->id_number)) {
            throw ValidationException::withMessages(['visitor_id' => 'A promotion of this number is already waiting to be sent.']);
        }

        $row = GlobalBlacklistOutbox::create([
            'action' => 'add',
            'id_type' => $visitor->id_type,
            'id_number' => $visitor->id_number,
            'name' => $visitor->name,
            'reason_category' => $data['reason_category'],
            'local_note' => $data['local_note'],
            'requested_by' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => $row->id, 'status' => 'pending']], 201);
    }

    /** "Cabut": only an active entry this yard registered (SEC-3); others are revoked by their own yard. */
    public function revoke(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['local_note' => ['required', 'string', 'max:1000']]);
        $entry = GlobalBlacklist::findOrFail($id);

        if ($entry->registered_by_yard !== (string) config('vms.sync.yard_code')) {
            return response()->json(['message' => "Only {$entry->registered_by_yard} can revoke this entry. Contact that yard, or let one visit through with a supervisor override."], 403);
        }
        if (! $entry->is_active) {
            return response()->json(['message' => 'This entry has already been revoked.'], 422);
        }
        if ($this->alreadyQueued('revoke', $entry->id_type, $entry->id_number)) {
            return response()->json(['message' => 'A revocation of this entry is already waiting to be sent.'], 422);
        }

        $row = GlobalBlacklistOutbox::create([
            'action' => 'revoke',
            'id_type' => $entry->id_type,
            'id_number' => $entry->id_number,
            'name' => $entry->name,
            'local_note' => $data['local_note'],
            'requested_by' => $request->user()->id,
        ]);

        return response()->json(['data' => ['id' => $row->id, 'status' => 'pending']], 201);
    }

    private function alreadyQueued(string $action, string $idType, string $idNumber): bool
    {
        return GlobalBlacklistOutbox::pending()
            ->where('action', $action)
            ->where('id_type', $idType)
            ->where('id_number', $idNumber)
            ->exists();
    }
}
