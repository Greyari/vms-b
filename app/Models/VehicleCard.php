<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleCard extends Model
{
    use HasFactory, HasUuidV7, SoftDeletes;

    protected $fillable = ['card_no', 'status'];

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
