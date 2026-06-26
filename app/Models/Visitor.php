<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    protected $fillable = ['ktp_number', 'name', 'company', 'rfid_uid', 'is_blacklisted', 'blacklist_reason'];

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
