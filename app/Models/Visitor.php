<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $fillable = ['id_type','id_number', 'name', 
    'company', 'is_blacklisted', 'blacklist_reason'];

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
