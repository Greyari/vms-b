<?php

namespace App\Traits;

use App\Services\ActivityLogger;

/**
 * Drop this trait onto any Eloquent model to automatically record
 * create / update / delete (and restore, for soft-deletable models)
 * activity, including old vs new field values on updates.
 */
trait LogsActivity
{
    /**
     * Attributes that should never be written to the activity log,
     * even if they change (secrets, noise, bookkeeping columns).
     * Override per-model with a `protected array $logExcept = [...]`
     * to add more.
     */
    protected static function activityLogExcept(): array
    {
        $defaults = ['password', 'remember_token', 'updated_at'];

        $modelSpecific = (new static())->logExcept ?? [];

        return array_unique([...$defaults, ...$modelSpecific]);
    }

    protected static function bootLogsActivity(): void
    {
        static::created(function ($model): void {
            ActivityLogger::log(
                action: 'created',
                subject: $model,
                old: null,
                new: self::filterAttributes($model->getAttributes()),
            );
        });

        static::updated(function ($model): void {
            $changed = array_diff_key(
                self::filterAttributes($model->getChanges()),
                array_flip(['id', 'created_at'])
            );

            // created/deleted_at-only touches (e.g. soft delete/restore) are
            // logged separately below - skip the noisy "updated" entry here.
            if (empty($changed)) {
                return;
            }

            $old = [];
            foreach (array_keys($changed) as $key) {
                $old[$key] = $model->getOriginal($key);
            }

            ActivityLogger::log(
                action: 'updated',
                subject: $model,
                old: $old,
                new: $changed,
            );
        });

        static::deleted(function ($model): void {
            ActivityLogger::log(
                action: 'deleted',
                subject: $model,
                old: self::filterAttributes($model->getAttributes()),
                new: null,
            );
        });

        // Only models using the SoftDeletes trait have a `restored` event to
        // hook into; calling static::restored() on a model that doesn't use
        // it would be a fatal error, so guard on the trait itself.
        if (method_exists(static::class, 'trashed')) {
            static::restored(function ($model): void {
                ActivityLogger::log(
                    action: 'restored',
                    subject: $model,
                    old: null,
                    new: self::filterAttributes($model->getAttributes()),
                );
            });
        }
    }

    protected static function filterAttributes(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::activityLogExcept()));
    }
}
