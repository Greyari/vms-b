<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\Pivot;

// Custom pivot for visit_visitor_card, since Laravel's default pivot
// doesn't auto-generate a UUID for the id column
class VisitVisitorCard extends Pivot
{
    use HasUuidV7;

    protected $table = 'visit_visitor_card';
}
