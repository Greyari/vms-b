<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasFactory, HasUuidV7, SoftDeletes;

    protected $fillable = ['staff_id', 'name', 'department'];

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
