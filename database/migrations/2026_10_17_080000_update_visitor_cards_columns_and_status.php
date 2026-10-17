<?php

use App\Models\VisitorCard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Drop old PostgreSQL status check constraint if present
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE visitor_cards DROP CONSTRAINT IF EXISTS visitor_cards_status_check');
        }

        Schema::table('visitor_cards', function (Blueprint $table): void {
            $table->string('status', 50)->default('available')->change();
            if (! Schema::hasColumn('visitor_cards', 'id_card_no')) {
                $table->string('id_card_no')->nullable()->after('card_no');
            }
            if (! Schema::hasColumn('visitor_cards', 'name')) {
                $table->string('name')->nullable()->after('id_card_no');
            }
            if (! Schema::hasColumn('visitor_cards', 'date_issued')) {
                $table->date('date_issued')->nullable()->after('name');
            }
        });

        // 2. Seed / Upsert the 12 official Visitor Green cards provided (skip during automated tests)
        if (app()->environment('testing')) {
            return;
        }

        $officialCards = [
            ['card_no' => 'ASL-2301', 'id_card_no' => '0005890502', 'name' => 'VISITOR GREEN', 'date_issued' => '2023-07-22'],
            ['card_no' => 'ASL-2302-A', 'id_card_no' => '0002112144', 'name' => 'VISITOR GREEN', 'date_issued' => '2025-11-11'],
            ['card_no' => 'ASL-2303', 'id_card_no' => '0011687078', 'name' => 'VISITOR GREEN', 'date_issued' => '2023-07-22'],
            ['card_no' => 'ASL-2304', 'id_card_no' => '0002148614', 'name' => 'VISITOR GREEN', 'date_issued' => '2023-07-22'],
            ['card_no' => 'ASL-2305', 'id_card_no' => '0007116224', 'name' => 'VISITOR GREEN', 'date_issued' => '2023-07-22'],
            ['card_no' => 'ASL-2306', 'id_card_no' => '0005911750', 'name' => 'VISITOR GREEN', 'date_issued' => '2023-07-22'],
            ['card_no' => 'ASL-2307', 'id_card_no' => '0007133593', 'name' => 'VISITOR GREEN', 'date_issued' => '2023-07-22'],
            ['card_no' => 'ASL-2308-A', 'id_card_no' => '0009977003', 'name' => 'VISITOR GREEN', 'date_issued' => '2025-11-11'],
            ['card_no' => 'ASL-2309-A', 'id_card_no' => '0002995122', 'name' => 'VISITOR GREEN', 'date_issued' => '2025-10-02'],
            ['card_no' => 'ASL-2310', 'id_card_no' => '0002245488', 'name' => 'VISITOR GREEN', 'date_issued' => '2023-07-22'],
            ['card_no' => 'ASL-2311', 'id_card_no' => '0004666214', 'name' => 'VISITOR GREEN', 'date_issued' => '2023-07-22'],
            ['card_no' => 'ASL-2312-A', 'id_card_no' => '00054720764', 'name' => 'VISITOR GREEN', 'date_issued' => '2026-03-14'],
        ];

        foreach ($officialCards as $item) {
            VisitorCard::updateOrCreate(
                ['card_no' => $item['card_no']],
                [
                    'id_card_no' => $item['id_card_no'],
                    'absenteeism_no' => $item['id_card_no'],
                    'name' => $item['name'],
                    'card_type' => 'visitor_green',
                    'date_issued' => $item['date_issued'],
                    'status' => 'available',
                    'is_active' => true,
                ]
            );
        }
    }

    public function down(): void
    {
        Schema::table('visitor_cards', function (Blueprint $table): void {
            $table->dropColumn(['id_card_no', 'name', 'date_issued']);
        });
    }
};
