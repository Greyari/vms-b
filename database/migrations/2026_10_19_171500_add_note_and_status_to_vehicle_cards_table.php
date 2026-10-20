<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehicle_cards DROP CONSTRAINT IF EXISTS vehicle_cards_status_check');
        }

        Schema::table('vehicle_cards', function (Blueprint $table): void {
            $table->string('status', 50)->default('available')->change();
            if (! Schema::hasColumn('vehicle_cards', 'note')) {
                $table->string('note')->nullable()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_cards', function (Blueprint $table): void {
            if (Schema::hasColumn('vehicle_cards', 'note')) {
                $table->dropColumn('note');
            }
        });
    }
};
