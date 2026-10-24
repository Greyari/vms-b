<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->string('plate_normalized')->nullable()->index();
            $table->uuid('created_by')->nullable()->index();
            $table->string('created_by_name')->nullable();
            $table->uuid('checked_out_by')->nullable()->index();
            $table->string('checked_out_by_name')->nullable();
        });

        // Safely backfill plate_normalized for existing rows in chunks
        DB::table('visits')
            ->whereNotNull('vehicle_no')
            ->where('vehicle_no', '!=', '')
            ->select(['id', 'vehicle_no'])
            ->orderBy('id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $row) {
                    $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper(trim((string) $row->vehicle_no)));
                    DB::table('visits')
                        ->where('id', $row->id)
                        ->update([
                            'plate_normalized' => $normalized !== '' ? $normalized : null,
                        ]);
                }
            });

        // Ensure vehicle_cards status is synchronized with open visits
        if (Schema::hasTable('vehicle_cards')) {
            $inUseVehicleCardIds = DB::table('visits')
                ->whereNull('checked_out_at')
                ->whereNotNull('vehicle_card_id')
                ->distinct()
                ->pluck('vehicle_card_id')
                ->all();

            if (! empty($inUseVehicleCardIds)) {
                DB::table('vehicle_cards')
                    ->whereIn('id', $inUseVehicleCardIds)
                    ->update(['status' => 'in_use']);
            }
        }
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropIndex(['plate_normalized']);
            $table->dropIndex(['created_by']);
            $table->dropIndex(['checked_out_by']);
            $table->dropColumn([
                'plate_normalized',
                'created_by',
                'created_by_name',
                'checked_out_by',
                'checked_out_by_name',
            ]);
        });
    }
};
