<?php

namespace App\Models;

use App\Enums\PreRegistrationStatus;
use App\Support\IdentityNumber;
use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pre-registration pulled from the cloud hub (hybrid spec §4.3). The hub deletes its
 * copy once this gate acknowledges the pull, so status, check-in and expiry live only
 * here. The sync command upserts by token, so a pull repeated before its ACK does not
 * duplicate rows.
 */
class PreRegistration extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'token', 'id_type', 'id_number', 'name', 'company', 'phone', 'gender', 'purpose',
        'host_name', 'host_department', 'scheduled_date', 'vehicle_no', 'status', 'pulled_at', 'visit_id',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date:Y-m-d',
            'pulled_at' => 'datetime',
            'status' => PreRegistrationStatus::class,
        ];
    }

    /** The QR code carries the token; USB scanners and people type it in any case. */
    public static function normalizeToken(?string $token): string
    {
        return strtoupper(trim((string) $token));
    }

    protected function token(): Attribute
    {
        return Attribute::make(set: fn (?string $value): string => self::normalizeToken($value));
    }

    protected function idNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value): string => IdentityNumber::normalize($value));
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }
}
