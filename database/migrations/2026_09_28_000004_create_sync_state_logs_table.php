<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Hybrid spec §4.3: cursor and last result per sync channel. last_seq is the hub's
 * sync_seq of the newest blacklist change already mirrored (decision PE-3); the header
 * indicator reads last_success_at (FE-4c, PE-6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_state_logs', function (Blueprint $table) {
            $table->string('sync_key', 50)->primary();
            $table->unsignedBigInteger('last_seq')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->string('status', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('records_synced')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_state_logs');
    }
};
