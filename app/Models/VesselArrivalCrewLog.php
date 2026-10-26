<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VesselArrivalCrewLog extends Model
{
    use HasFactory, HasUuidV7;

    protected $table = 'vessel_arrival_crew_logs';

    protected $fillable = [
        'vessel_arrival_id',
        'visitor_id',
        'visit_id',
        'action',
        'source',
        'performed_by',
        'performed_by_name',
        'notes',
        'logged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logged_at' => 'datetime',
        ];
    }

    public function vesselArrival()
    {
        return $this->belongsTo(VesselArrival::class);
    }

    public function visitor()
    {
        return $this->belongsTo(Visitor::class);
    }

    public function visit()
    {
        return $this->belongsTo(Visit::class);
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
