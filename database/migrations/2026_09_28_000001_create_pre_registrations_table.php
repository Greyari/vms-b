<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Hybrid spec §4.3: this gate's copy of pre-registrations pulled from the cloud hub.
 * The hub deletes its row after this gate's ACK, so status and check-in live only here
 * (decision PE-4). No yard_code: the gate only ever pulls its own (PE-2, SEC-3).
 * gender stays empty until the officer asks at check-in (BE-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pre_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Issued by the hub; the QR code carries only this value.
            $table->string('token', 20)->unique();
            $table->string('id_type', 20);
            $table->string('id_number', 50)->index();
            $table->string('name', 150);
            $table->string('company', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('gender', 1)->nullable();
            $table->text('purpose')->nullable();
            $table->string('host_name', 150)->nullable();
            $table->string('host_department', 100)->nullable();
            $table->date('scheduled_date')->index();
            $table->string('vehicle_no', 30)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('pulled_at');
            $table->uuid('visit_id')->nullable();
            $table->foreign('visit_id')->references('id')->on('visits')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pre_registrations');
    }
};
