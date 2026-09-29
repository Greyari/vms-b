<?php

namespace App\Models;

use App\Enums\BlacklistReasonCategory;
use App\Support\IdentityNumber;
use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promote-to-global or revoke request made on this gate and not yet accepted by the
 * hub (hybrid spec §4.3). It is written first and sent by the sync command later, so it
 * survives an internet outage. local_note never leaves this server (decision SEC-6d);
 * LogsActivity records who asked and why (BE-4).
 */
class GlobalBlacklistOutbox extends Model
{
    use HasUuidV7, LogsActivity;

    protected $table = 'global_blacklist_outbox';

    protected $fillable = [
        'action', 'id_type', 'id_number', 'name', 'reason_category', 'local_note',
        'requested_by', 'sent_at', 'attempts', 'last_error',
    ];

    protected $attributes = [
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason_category' => BlacklistReasonCategory::class,
            'sent_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    protected function idNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value): string => IdentityNumber::normalize($value));
    }

    /**
     * Requests the hub has not accepted yet, oldest first.
     *
     * @param  Builder<GlobalBlacklistOutbox>  $query
     * @return Builder<GlobalBlacklistOutbox>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('sent_at')->orderBy('created_at');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
