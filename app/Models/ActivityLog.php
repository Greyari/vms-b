<?php

namespace App\Models;

use App\Traits\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasUuidV7;

    /**
     * We only ever write created_at (set by the DB default), never update it.
     */
    public $timestamps = false;

    protected $fillable = [
        'causer_id',
        'causer_name',
        'causer_email',
        'action',
        'subject_type',
        'subject_id',
        'subject_label',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * The user who performed the action, if any.
     * Kept as a loose relation (not a hard FK) so logs survive user deletion.
     */
    public function causer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'causer_id');
    }

    /**
     * Short readable name for the model the log is about, e.g. "Staff".
     */
    public function getSubjectShortTypeAttribute(): ?string
    {
        if (!$this->subject_type) {
            return null;
        }

        return class_basename($this->subject_type);
    }
}
