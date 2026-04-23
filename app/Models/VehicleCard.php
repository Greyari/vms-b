<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleCard extends Model
{
    use SoftDeletes;

    protected $fillable = ['card_no', 'status'];

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
