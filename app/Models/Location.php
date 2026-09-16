<?php

namespace App\Models;

use App\Enums\LocationType;
use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Location extends Model
{
    use HasFactory, HasUuidV7, LogsActivity, SoftDeletes;

    protected $fillable = [
        'code', 'name', 'type', 'call_sign', 'sign', 'flag', 'port', 'vessel_type', 'captain', 'pic',
        'local_agent_company', 'local_agent_name', 'local_agent_phone', 'local_agent_location',
        'foreign_agent_company', 'foreign_agent_name', 'foreign_agent_phone', 'foreign_agent_location',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A place has no IMO number. VMS-old keyed it by its own name,
        // upper-cased with spaces turned into underscores; the same spelling
        // keeps imported and newly created places alike. The code is fixed at
        // creation — renaming the place later must not break its visits.
        static::creating(function (Location $location): void {
            if (blank($location->code) && $location->type === LocationType::Location) {
                $location->code = self::codeFromName((string) $location->name);
            }
        });
    }

    public static function codeFromName(string $name): string
    {
        return Str::upper((string) preg_replace('/\s+/u', '_', trim($name)));
    }

    public function crew()
    {
        return $this->hasMany(Visitor::class, 'vessel_id');
    }

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }

    public function arrivals()
    {
        return $this->hasMany(VesselArrival::class, 'location_id');
    }
}
