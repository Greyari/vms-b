<?php

namespace App\Models;

use App\Enums\BlacklistReasonCategory;
use App\Support\IdentityNumber;
use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * This gate's copy of one global blacklist entry (hybrid spec §4.3). Only the sync
 * command writes here; the hub owns the entry and orders every change with sync_seq
 * (decision PE-3). A revoked entry stays with is_active = false — nothing is deleted.
 * The local blacklist is visitors.is_blacklisted and never leaves this server (PE-5).
 */
class GlobalBlacklist extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'hub_id', 'id_type', 'id_number', 'name', 'reason_category', 'registered_by_yard', 'is_active', 'sync_seq',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason_category' => BlacklistReasonCategory::class,
            'is_active' => 'boolean',
            'sync_seq' => 'integer',
        ];
    }

    protected function idNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value): string => IdentityNumber::normalize($value));
    }

    /**
     * Active entries for an identity number, whatever its document type: nobody slips past
     * by showing a passport instead of the KTP that was listed (decision SEC-4). The caller
     * decides what a match on another id_type means — a warning, not a refusal.
     *
     * @param  Builder<GlobalBlacklist>  $query
     * @return Builder<GlobalBlacklist>
     */
    public function scopeActiveForNumber(Builder $query, string $idNumber): Builder
    {
        return $query->where('is_active', true)->where('id_number', IdentityNumber::normalize($idNumber));
    }
}
