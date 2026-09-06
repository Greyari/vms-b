<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    use HasFactory, HasUuidV7, LogsActivity;

    protected $fillable = [
        'id_type', 'id_number', 'name', 'company',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_blacklisted' => 'boolean',
        ];
    }

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
