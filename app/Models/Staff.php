<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use SoftDeletes;

    protected $fillable = ['staff_id', 'name', 'department'];

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
