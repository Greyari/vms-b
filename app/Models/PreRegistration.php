<?php

namespace App\Models;

use App\Enums\PreRegistrationStatus;
use App\Services\MasterImportService;
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

    protected function gender(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => MasterImportService::parseGender($value));
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** Pending pre-registrations whose day has passed become expired (hybrid spec §6.2, PE-4). */
    public static function expirePast(): int
    {
        return static::where('status', PreRegistrationStatus::Pending->value)
            ->whereDate('scheduled_date', '<', now()->toDateString())
            ->update(['status' => PreRegistrationStatus::Expired->value, 'updated_at' => now()]);
    }

    /** Status as the counter should see it: a pending row whose day has passed is expired. */
    public function effectiveStatus(): PreRegistrationStatus
    {
        if ($this->status === PreRegistrationStatus::Pending && $this->scheduled_date->toDateString() < now()->toDateString()) {
            return PreRegistrationStatus::Expired;
        }

        return $this->status;
    }

    public function markCheckedIn(Visit $visit): void
    {
        $this->forceFill(['status' => PreRegistrationStatus::CheckedIn, 'visit_id' => $visit->id])->save();
    }

    /**
     * A walk-in who arrived before this pre-registration was pulled: same identity number, a visit on
     * the scheduled day, not yet linked to another pre-registration (decision PE-4).
     */
    public function linkWalkIn(): void
    {
        if ($this->status !== PreRegistrationStatus::Pending) {
            return;
        }

        $visit = Visit::whereDate('date', $this->scheduled_date->toDateString())
            ->whereHas('visitor', fn ($query) => $query->where('id_number', $this->id_number))
            ->whereNotIn('id', static::whereNotNull('visit_id')->select('visit_id'))
            ->oldest()
            ->first();

        if ($visit !== null) {
            $this->markCheckedIn($visit);
        }
    }

    /**
     * The pending pre-registration a walk-in fulfils: the same identity number booked for the day of
     * the visit (decision PE-4).
     */
    public static function pendingFor(string $idNumber, string $date): ?self
    {
        return static::where('status', PreRegistrationStatus::Pending->value)
            ->where('id_number', IdentityNumber::normalize($idNumber))
            ->whereDate('scheduled_date', $date)
            ->oldest()
            ->first();
    }
}
