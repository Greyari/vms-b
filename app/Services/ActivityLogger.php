<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Str;

class ActivityLogger
{
    /**
     * Log a create/update/delete/restore event for an Eloquent model.
     *
     * @param  string  $action  'created' | 'updated' | 'deleted' | 'restored'
     * @param  Model  $subject  The model instance the action happened to
     * @param  array|null  $old  Old attribute values (updates only)
     * @param  array|null  $new  New attribute values
     */
    public static function log(string $action, Model $subject, ?array $old = null, ?array $new = null): void
    {
        self::write(
            action: $action,
            subjectType: get_class($subject),
            subjectId: (string) $subject->getKey(),
            subjectLabel: self::labelFor($subject),
            old: $old,
            new: $new,
        );
    }

    /**
     * Log an event that isn't tied to a model, e.g. login / logout.
     */
    public static function logEvent(string $action, ?array $extra = null): void
    {
        self::write(
            action: $action,
            subjectType: null,
            subjectId: null,
            subjectLabel: null,
            old: null,
            new: $extra,
        );
    }

    protected static function write(
        string $action,
        ?string $subjectType,
        ?string $subjectId,
        ?string $subjectLabel,
        ?array $old,
        ?array $new,
    ): void {
        $causer = Auth::user();
        $request = RequestFacade::instance();

        ActivityLog::create([
            'id' => Str::uuid7()->toString(),
            'causer_id' => $causer?->getKey(),
            'causer_name' => $causer?->name,
            'causer_email' => $causer?->email,
            'causer_username' => $causer?->username,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $subjectLabel,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 500, '') : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Best-effort human-readable label for a subject (used so the log stays
     * readable even after the record itself is deleted).
     */
    protected static function labelFor(Model $subject): ?string
    {
        foreach (['name', 'full_name', 'plate_number', 'card_number', 'title', 'email'] as $field) {
            if (! empty($subject->{$field})) {
                return (string) $subject->{$field};
            }
        }

        return null;
    }
}
