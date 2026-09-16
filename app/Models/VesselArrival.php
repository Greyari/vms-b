<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VesselArrival extends Model
{
    use HasFactory, HasUuidV7, LogsActivity;

    protected $fillable = [
        'location_id', 'vessel_name', 'sign_in_date', 'sign_out_date', 'sign_in_port', 'sign_out_port', 'captain',
        'local_agent_company', 'local_agent_name', 'local_agent_phone', 'local_agent_location',
        'foreign_agent_company', 'foreign_agent_name', 'foreign_agent_phone', 'foreign_agent_location',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sign_in_date' => 'date',
            'sign_out_date' => 'date',
        ];
    }

    public function vessel()
    {
        return $this->belongsTo(Location::class, 'location_id')->withTrashed();
    }

    public function crew()
    {
        return $this->belongsToMany(Visitor::class, 'vessel_arrival_crew')
            ->using(VesselArrivalCrew::class)
            ->withTimestamps();
    }
}
