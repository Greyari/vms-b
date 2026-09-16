<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\Pivot;

// Custom pivot for vessel_arrival_crew: Laravel's default pivot does not
// generate a UUID for the id column (same pattern as VisitVisitorCard).
class VesselArrivalCrew extends Pivot
{
    use HasUuidV7;

    protected $table = 'vessel_arrival_crew';
}
