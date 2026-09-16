<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suggestion list for a visitor's nationality and a vessel's flag, filled from
 * VMS-old's Nationality table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nationalities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nationalities');
    }
};
