<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VMS-old ESSVisitorCardMaster: the paired absenteeism card number, the card
 * category (kept in its Note column, used as a report filter) and whether the
 * card may still be issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitor_cards', function (Blueprint $table): void {
            $table->string('absenteeism_no')->nullable();
            $table->string('card_type')->nullable()->index();
            $table->string('note')->nullable();
            $table->boolean('is_active')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('visitor_cards', function (Blueprint $table): void {
            $table->dropIndex(['card_type']);
            $table->dropColumn(['absenteeism_no', 'card_type', 'note', 'is_active']);
        });
    }
};
