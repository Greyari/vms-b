<?php

namespace App\Models;

use App\Enums\VisitorType;
use App\Services\MasterImportService;
use App\Support\IdentityNumber;
use App\Support\SequenceLock;
use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Visitor extends Model
{
    use HasFactory, HasUuidV7, LogsActivity;

    /**
     * visitor_no and the blacklist columns are deliberately not fillable: the
     * number is assigned by the system, the blacklist only through its own
     * endpoints.
     */
    protected $fillable = [
        'id_type', 'id_number', 'name', 'company',
        'visitor_type', 'gender', 'birth_date', 'birth_place', 'nationality', 'occupation',
        'id_issued_at', 'id_expires_at', 'id_lifetime',
        'vessel_id', 'certificate', 'certificate_no', 'seaman_book_no', 'seaman_book_expires_at',
        'badge_code', 'offences', 'blacklisted_at', 'remark',
    ];

    /**
     * In-memory defaults matching the database defaults, so a freshly created
     * model reads the same as a reloaded one.
     */
    protected $attributes = [
        'visitor_type' => 'visitor',
        'id_lifetime' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_blacklisted' => 'boolean',
            'visitor_no' => 'integer',
            'visitor_type' => VisitorType::class,
            'birth_date' => 'date',
            'id_issued_at' => 'date',
            'id_expires_at' => 'date',
            'id_lifetime' => 'boolean',
            'seaman_book_expires_at' => 'date',
            'blacklisted_at' => 'date',
        ];
    }

    /**
     * Normalize gender to 'M' or 'F' so it fits the character(1) column and matches imports.
     */
    protected function gender(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => MasterImportService::parseGender($value),
        );
    }

    protected static function booted(): void
    {
        static::creating(function (Visitor $visitor): void {
            if ($visitor->visitor_no === null) {
                $visitor->visitor_no = self::nextVisitorNo();
            }
        });
    }

    /**
     * Next number at or above the configured floor. On PostgreSQL the
     * transaction-scoped advisory lock taken through SequenceLock serialises
     * allocations: a second request reads the maximum only after the first has
     * committed. Visitors must therefore be created inside a database
     * transaction (SequenceLock refuses otherwise); SQLite serialises writers
     * by itself. The unique index stays the last line of defence, and the
     * callers that create visitors retry when UniqueViolation::isRetryable()
     * accepts the violation: VisitController::store with its own loop, and
     * POST /visitors and the imports through App\Support\NumberingRetry.
     */
    public static function nextVisitorNo(): int
    {
        SequenceLock::acquire('visitor_no');

        $floor = (int) config('vms.visitor_no_floor');

        $highest = static::query()
            ->where('visitor_no', '>=', $floor)
            ->max('visitor_no');

        return $highest === null ? $floor : ((int) $highest) + 1;
    }

    /**
     * Stored normalized whichever Eloquent path writes it. Bulk query-builder
     * writes (the legacy importer) must call IdentityNumber::normalize() themselves.
     */
    protected function idNumber(): Attribute
    {
        return Attribute::make(set: fn (?string $value): string => IdentityNumber::normalize($value));
    }

    public function visits()
    {
        return $this->hasMany(Visit::class);
    }

    public function vessel()
    {
        return $this->belongsTo(Location::class, 'vessel_id')->withTrashed();
    }

    public function vesselArrivals()
    {
        return $this->belongsToMany(VesselArrival::class, 'vessel_arrival_crew')
            ->using(VesselArrivalCrew::class)
            ->withTimestamps();
    }

    public function blacklists()
    {
        return $this->hasMany(Blacklist::class)->orderBy('blacklisted_at', 'desc');
    }

    public function activeBlacklist()
    {
        return $this->hasOne(Blacklist::class)->where('is_active', true)->latestOfMany('blacklisted_at');
    }
}
