<?php

use App\Services\MasterImportService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Normalize staff gender to 'M' or 'F'
        DB::table('staff')
            ->whereNotNull('gender')
            ->whereNotIn('gender', ['M', 'F'])
            ->orderBy('id')
            ->each(function ($staff): void {
                $normalized = MasterImportService::parseGender($staff->gender);
                DB::table('staff')->where('id', $staff->id)->update(['gender' => $normalized]);
            });

        // Normalize visitors gender to 'M' or 'F'
        DB::table('visitors')
            ->whereNotNull('gender')
            ->whereNotIn('gender', ['M', 'F'])
            ->orderBy('id')
            ->each(function ($visitor): void {
                $normalized = MasterImportService::parseGender($visitor->gender);
                DB::table('visitors')->where('id', $visitor->id)->update(['gender' => $normalized]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration needed
    }
};
