<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Enforce that one visitor cannot have multiple simultaneous active placements (expected or checked_in).
        // Uses a PostgreSQL partial unique index.
        DB::statement("
            CREATE UNIQUE INDEX IF NOT EXISTS unique_active_crew_placement 
            ON vessel_arrival_crew (visitor_id) 
            WHERE status IN ('expected', 'checked_in')
        ");

        Schema::table('vessel_arrival_crew', function (Blueprint $table): void {
            $table->index(['vessel_arrival_id', 'status'], 'idx_vac_arrival_status');
            $table->index(['visitor_id', 'status'], 'idx_vac_visitor_status');
        });
    }

    public function down(): void
    {
        Schema::table('vessel_arrival_crew', function (Blueprint $table): void {
            $table->dropIndex('idx_vac_arrival_status');
            $table->dropIndex('idx_vac_visitor_status');
        });

        DB::statement('DROP INDEX IF EXISTS unique_active_crew_placement');
    }
};
