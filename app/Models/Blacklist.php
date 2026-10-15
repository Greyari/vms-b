<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blacklist extends Model
{
    use HasFactory, HasUuidV7, LogsActivity;

    protected $fillable = [
        'visitor_id',
        'name',
        'badge_code',
        'id_number',
        'offences',
        'blacklisted_at',
        'remark',
        'company',
        'is_active',
        'unblacklisted_at',
    ];

    protected $casts = [
        'blacklisted_at' => 'date',
        'unblacklisted_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'date',
        'is_connected',
    ];

    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->blacklisted_at?->format('Y-m-d')
        );
    }

    public function getIsConnectedAttribute(): bool
    {
        return ! empty($this->visitor_id);
    }

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
