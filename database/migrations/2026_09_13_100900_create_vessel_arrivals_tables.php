<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VMS-old "Crew Arrival Data": a vessel's arrival (ESSVesselTransaction) and the
 * crew who came with it (ESSCrewTransaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vessel_arrivals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('location_id')->constrained('locations')->restrictOnDelete();
            $table->string('vessel_name')->nullable();
            $table->date('sign_in_date');
            $table->date('sign_out_date')->nullable();
            $table->string('sign_in_port')->nullable();
            $table->string('sign_out_port')->nullable();
            $table->string('captain')->nullable();
            $table->string('local_agent_company')->nullable();
            $table->string('local_agent_name')->nullable();
            $table->string('local_agent_phone')->nullable();
            $table->string('local_agent_location')->nullable();
            $table->string('foreign_agent_company')->nullable();
            $table->string('foreign_agent_name')->nullable();
            $table->string('foreign_agent_phone')->nullable();
            $table->string('foreign_agent_location')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'sign_in_date']);
        });

        Schema::create('vessel_arrival_crew', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('vessel_arrival_id')->constrained('vessel_arrivals')->cascadeOnDelete();
            $table->foreignUuid('visitor_id')->constrained('visitors')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['vessel_arrival_id', 'visitor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vessel_arrival_crew');
        Schema::dropIfExists('vessel_arrivals');
    }
};
