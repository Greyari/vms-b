<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2 — identity is (id_type, id_number), not id_number alone. A KTP and a
 * passport may legitimately share the same digits, so the single-column
 * unique on visitors.id_number is replaced by a composite unique on
 * (id_type, id_number). Safe: no duplicate id_number values can exist under
 * the old unique, so the composite constraint always builds. Idempotent via
 * name guards.
 */
return new class extends Migration
{
    /**
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
        Schema::table('visitors', function (Blueprint $table): void {
            if ($this->hasIndex('visitors', 'visitors_id_number_unique')) {
                $table->dropUnique('visitors_id_number_unique');
            }
            if (! $this->hasIndex('visitors', 'visitors_id_type_id_number_unique')) {
                $table->unique(['id_type', 'id_number']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            if ($this->hasIndex('visitors', 'visitors_id_type_id_number_unique')) {
                $table->dropUnique('visitors_id_type_id_number_unique');
            }
            if (! $this->hasIndex('visitors', 'visitors_id_number_unique')) {
                $table->unique('id_number');
            }
        });
    }
};
