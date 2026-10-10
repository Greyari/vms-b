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
        Schema::table('visitors', function (Blueprint $table) {
            $table->string('badge_code')->nullable()->index();
            $table->text('offences')->nullable();
            $table->date('blacklisted_at')->nullable()->index();
            $table->text('remark')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            $table->dropIndex(['badge_code']);
            $table->dropIndex(['blacklisted_at']);
            $table->dropColumn(['badge_code', 'offences', 'blacklisted_at', 'remark']);
        });
    }
};
