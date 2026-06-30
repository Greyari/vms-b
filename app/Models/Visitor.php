<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    use HasUuidV7;

    protected $fillable = [
        'id_type', 'id_number', 'name',
        'company', 'is_blacklisted', 'blacklist_reason'
    ];

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
