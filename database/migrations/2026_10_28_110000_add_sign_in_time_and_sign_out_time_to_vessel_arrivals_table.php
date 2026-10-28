<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('vessel_arrivals', function (Blueprint $table): void {
            $table->time('sign_in_time')->nullable()->after('sign_in_date');
            $table->time('sign_out_time')->nullable()->after('sign_out_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vessel_arrivals', function (Blueprint $table): void {
            $table->dropColumn(['sign_in_time', 'sign_out_time']);
        });
    }
};
