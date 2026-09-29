<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Hybrid spec §4.3: this gate's copy of the global blacklist. One row per hub entry
 * (hub_id), holding its latest state; the hub orders every change with sync_seq
 * (decision PE-3), so there is no version column and nothing is ever deleted.
 * Lookups go by id_number alone, across document types (SEC-4). Only a fixed reason
 * category arrives from the hub; free-text reasons never leave a yard (SEC-6d).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_blacklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('hub_id')->unique();
            $table->string('id_type', 20);
            $table->string('id_number', 50)->index();
            $table->string('name', 150);
            $table->string('reason_category', 30);
            $table->string('registered_by_yard', 20);
            $table->boolean('is_active');
            $table->unsignedBigInteger('sync_seq')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_blacklists');
    }
};
