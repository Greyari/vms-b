<?php

namespace App\Models;

use App\Services\MasterImportService;
use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Staff extends Model
{
    use HasFactory, HasUuidV7, LogsActivity, SoftDeletes;

    protected $fillable = [
        'staff_id', 'name', 'department',
        'gender', 'birth_date', 'company', 'address', 'occupation', 'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Normalize gender to 'M' or 'F'.
     */
    protected function gender(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => MasterImportService::parseGender($value),
        );
    }

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }
}
