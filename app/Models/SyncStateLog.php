<?php

namespace App\Models;

use App\Enums\SyncKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Cursor and last result per sync channel (hybrid spec §4.3). last_seq is the hub's
 * sync_seq of the newest blacklist change already mirrored; the header indicator reads
 * last_success_at.
 */
class SyncStateLog extends Model
{
    protected $primaryKey = 'sync_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'sync_key', 'last_seq', 'last_attempt_at', 'last_success_at', 'status', 'last_error', 'records_synced',
    ];

    protected $attributes = [
        'last_seq' => 0,
        'records_synced' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sync_key' => SyncKey::class,
            'last_seq' => 'integer',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'records_synced' => 'integer',
        ];
    }
}
