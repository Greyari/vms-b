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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Who did it. Nullable + snapshot fields so the log still reads
            // correctly even if the user account is later deleted.
            $table->uuid('causer_id')->nullable()->index();
            $table->string('causer_name')->nullable();
            $table->string('causer_email')->nullable();

            // What happened: created | updated | deleted | restored | login | logout
            $table->string('action', 30)->index();

            // What it happened to (nullable for events like login/logout that
            // aren't tied to a specific model).
            $table->string('subject_type')->nullable()->index();
            $table->string('subject_id')->nullable()->index();
            // Human-friendly label snapshot (e.g. staff name, visitor name)
            // so the log stays readable even after the record is deleted.
            $table->string('subject_label')->nullable();

            // Old vs new field values for updates; full attribute set for
            // creates/deletes. Kept as JSON so we don't need a rigid schema.
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
