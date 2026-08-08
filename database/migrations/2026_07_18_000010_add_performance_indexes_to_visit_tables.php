<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B10 — PostgreSQL does not auto-index foreign-key columns (unlike MySQL), so
 * the hot lookup/sort columns on visits + the pivot were doing sequential
 * scans. This migration is additive and idempotent: every index is guarded by
 * a name check against the live schema so re-running is safe and it never
 * collides with the pre-existing `visits_ticket_number_unique`.
 */
return new class extends Migration
{
    /**
     * Existing index names for a table, lower-cased for comparison.
     *
     * @return array<int, string>
     */
    private function existingIndexes(string $table): array
    {
        return array_map(
            static fn (array $index): string => strtolower((string) $index['name']),
            Schema::getIndexes($table)
        );
    }

    private function hasIndex(string $table, string $name): bool
    {
        return in_array(strtolower($name), $this->existingIndexes($table), true);
    }

    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            if (! $this->hasIndex('visits', 'visits_visitor_id_index')) {
                $table->index('visitor_id');
            }
            if (! $this->hasIndex('visits', 'visits_staff_id_index')) {
                $table->index('staff_id');
            }
            if (! $this->hasIndex('visits', 'visits_vehicle_card_id_index')) {
                $table->index('vehicle_card_id');
            }
            if (! $this->hasIndex('visits', 'visits_date_index')) {
                $table->index('date');
            }
            if (! $this->hasIndex('visits', 'visits_time_out_index')) {
                $table->index('time_out');
            }
            // ticket_number already carries a UNIQUE index
            // (visits_ticket_number_unique) from an earlier migration; only add
            // one if it is somehow missing.
            if (! $this->hasIndex('visits', 'visits_ticket_number_unique')
                && ! $this->hasIndex('visits', 'visits_ticket_number_index')) {
                $table->unique('ticket_number');
            }
        });

        Schema::table('visit_visitor_card', function (Blueprint $table): void {
            if (! $this->hasIndex('visit_visitor_card', 'visit_visitor_card_visit_id_visitor_card_id_unique')) {
                $table->unique(['visit_id', 'visitor_card_id']);
            }
            if (! $this->hasIndex('visit_visitor_card', 'visit_visitor_card_visitor_card_id_index')) {
                $table->index('visitor_card_id');
            }
        });

        Schema::table('visitors', function (Blueprint $table): void {
            if (! $this->hasIndex('visitors', 'visitors_is_blacklisted_index')) {
                $table->index('is_blacklisted');
            }
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            if ($this->hasIndex('visits', 'visits_visitor_id_index')) {
                $table->dropIndex('visits_visitor_id_index');
            }
            if ($this->hasIndex('visits', 'visits_staff_id_index')) {
                $table->dropIndex('visits_staff_id_index');
            }
            if ($this->hasIndex('visits', 'visits_vehicle_card_id_index')) {
                $table->dropIndex('visits_vehicle_card_id_index');
            }
            if ($this->hasIndex('visits', 'visits_date_index')) {
                $table->dropIndex('visits_date_index');
            }
            if ($this->hasIndex('visits', 'visits_time_out_index')) {
                $table->dropIndex('visits_time_out_index');
            }
        });

        Schema::table('visit_visitor_card', function (Blueprint $table): void {
            if ($this->hasIndex('visit_visitor_card', 'visit_visitor_card_visit_id_visitor_card_id_unique')) {
                $table->dropUnique('visit_visitor_card_visit_id_visitor_card_id_unique');
            }
            if ($this->hasIndex('visit_visitor_card', 'visit_visitor_card_visitor_card_id_index')) {
                $table->dropIndex('visit_visitor_card_visitor_card_id_index');
            }
        });

        Schema::table('visitors', function (Blueprint $table): void {
            if ($this->hasIndex('visitors', 'visitors_is_blacklisted_index')) {
                $table->dropIndex('visitors_is_blacklisted_index');
            }
        });
    }
};
