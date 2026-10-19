<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessel_arrival_crew_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vessel_arrival_id')->constrained('vessel_arrivals')->cascadeOnDelete();
            $table->foreignUuid('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->string('action', 20); // 'check_in' | 'check_out'
            $table->string('source', 30)->default('manual'); // 'manual' | 'visit_checkin' | 'vessel_signon' | 'vessel_signout'
            $table->foreignUuid('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('performed_by_name')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->index(['vessel_arrival_id', 'logged_at']);
            $table->index(['visitor_id', 'logged_at']);
        });

        // Backfill existing vessel_arrival_crew rows so existing crew have initial log history
        $existingPivots = DB::table('vessel_arrival_crew')->get();
        foreach ($existingPivots as $pivot) {
            $checkedInAt = $pivot->checked_in_at ?? $pivot->created_at ?? now();
            DB::table('vessel_arrival_crew_logs')->insert([
                'id' => (string) Str::uuid7(),
                'vessel_arrival_id' => $pivot->vessel_arrival_id,
                'visitor_id' => $pivot->visitor_id,
                'visit_id' => null,
                'action' => 'check_in',
                'source' => 'vessel_signon',
                'performed_by' => null,
                'performed_by_name' => 'System',
                'notes' => $pivot->notes ?? 'Terdaftar pada manifest kedatangan kapal',
                'logged_at' => $checkedInAt,
                'created_at' => $checkedInAt,
                'updated_at' => $checkedInAt,
            ]);

            if (($pivot->status ?? 'checked_in') === 'checked_out' && $pivot->checked_out_at) {
                DB::table('vessel_arrival_crew_logs')->insert([
                    'id' => (string) Str::uuid7(),
                    'vessel_arrival_id' => $pivot->vessel_arrival_id,
                    'visitor_id' => $pivot->visitor_id,
                    'visit_id' => null,
                    'action' => 'check_out',
                    'source' => 'manual',
                    'performed_by' => null,
                    'performed_by_name' => 'System',
                    'notes' => $pivot->notes ?? 'Check-out kru kapal',
                    'logged_at' => $pivot->checked_out_at,
                    'created_at' => $pivot->checked_out_at,
                    'updated_at' => $pivot->checked_out_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_arrival_crew_logs');
    }
};
