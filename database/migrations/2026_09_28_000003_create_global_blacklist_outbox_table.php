<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Hybrid spec §4.3: promote-to-global and revoke requests made on this gate, kept until
 * the hub accepts them, so they survive an internet outage. local_note is the officer's
 * free text and never leaves this server (decision SEC-6d).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_blacklist_outbox', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('action', 10);
            $table->string('id_type', 20);
            $table->string('id_number', 50)->index();
            $table->string('name', 150);
            $table->string('reason_category', 30)->nullable();
            $table->text('local_note')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['sent_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_blacklist_outbox');
    }
};
