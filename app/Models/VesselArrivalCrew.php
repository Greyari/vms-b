<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

// Custom pivot for vessel_arrival_crew: Laravel's default pivot does not
// generate a UUID for the id column (same pattern as VisitVisitorCard).
class VesselArrivalCrew extends Pivot
{
    use HasUuidV7;

    protected $table = 'vessel_arrival_crew';

    protected $fillable = [
        'id',
        'vessel_arrival_id',
        'visitor_id',
        'status',
        'checked_in_at',
        'checked_out_at',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VesselArrivalCrew $pivot): void {
            if (empty($pivot->id)) {
                $pivot->id = (string) Str::uuid7();
            }
            if (blank($pivot->status)) {
                $pivot->status = 'checked_in';
            }
            if ($pivot->checked_in_at === null && $pivot->status === 'checked_in') {
                $pivot->checked_in_at = now();
            }
        });
    }
}
