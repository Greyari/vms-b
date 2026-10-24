<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    use HasFactory, HasUuidV7, LogsActivity;

    protected $fillable = [
        'visitor_id', 'staff_id', 'vehicle_card_id', 'location_id',
        'purpose', 'date', 'time_in', 'time_out', 'checked_out_at',
        'vehicle_no', 'plate_normalized', 'ticket_number',
        'location_name', 'host_name', 'visitor_company', 'legacy_registration_no',
        'created_by', 'created_by_name', 'checked_out_by', 'checked_out_by_name',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_out_at' => 'datetime',
        ];
    }

    public static function normalizePlate(?string $plate): ?string
    {
        if ($plate === null) {
            return null;
        }
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($plate)));

        return $normalized !== '' ? $normalized : null;
    }

    protected static function booted(): void
    {
        static::saving(function (Visit $visit): void {
            $visit->plate_normalized = self::normalizePlate($visit->vehicle_no);
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function checkedOutByUser()
    {
        return $this->belongsTo(User::class, 'checked_out_by');
    }

    /** Visits whose visitor has not checked out, however many days ago they came in. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('checked_out_at');
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function location()
    {
        return $this->belongsTo(Location::class)->withTrashed();
    }

    public function visitorCards()
    {
        return $this->belongsToMany(VisitorCard::class)->using(VisitVisitorCard::class)->withTimestamps();
    }

    public function vehicleCard()
    {
        return $this->belongsTo(VehicleCard::class)->withTrashed();
    }

    public function getDaysOpenAttribute(): int
    {
        if ($this->date === null) {
            return 0;
        }

        return $this->checked_out_at === null
            ? (int) Carbon::parse($this->date)->diffInDays(now()->startOfDay())
            : (int) Carbon::parse($this->date)->diffInDays($this->checked_out_at->startOfDay());
    }
}
