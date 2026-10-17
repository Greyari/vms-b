<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vessel_arrival_crew', function (Blueprint $table): void {
            $table->string('status', 30)->default('checked_in')->after('visitor_id');
            $table->timestamp('checked_in_at')->nullable()->after('status');
            $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
            $table->string('notes')->nullable()->after('checked_out_at');
        });

        DB::table('vessel_arrival_crew')
            ->whereNull('checked_in_at')
            ->update([
                'status' => 'checked_in',
                'checked_in_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('vessel_arrival_crew', function (Blueprint $table): void {
            $table->dropColumn(['status', 'checked_in_at', 'checked_out_at', 'notes']);
        });
    }
};
