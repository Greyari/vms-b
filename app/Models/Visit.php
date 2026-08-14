<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visit extends Model
{
    use HasFactory, HasUuidV7, LogsActivity;

    protected $fillable = [
        'visitor_id', 'staff_id', 'vehicle_card_id',
        'purpose', 'date', 'time_in', 'time_out', 'vehicle_no', 'ticket_number'
    ];

    public function visitor()
    {
        return $this->belongsTo(Visitor::class);
    }

    public function staff()
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function visitorCards()
    {
        return $this->belongsToMany(VisitorCard::class)->using(VisitVisitorCard::class)->withTimestamps();
    }

    public function vehicleCard()
    {
        return $this->belongsTo(VehicleCard::class)->withTrashed();
    }
}
