<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vessels and places a visitor goes to (VMS-old ESSVesselMaster). code is the
 * IMO number for a vessel and a name-derived key for a place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type', 20)->index();
            $table->string('call_sign')->nullable();
            $table->string('sign')->nullable();
            $table->string('flag')->nullable();
            $table->string('port')->nullable();
            $table->string('vessel_type')->nullable();
            $table->string('captain')->nullable();
            $table->string('pic')->nullable();
            $table->string('local_agent_company')->nullable();
            $table->string('local_agent_name')->nullable();
            $table->string('local_agent_phone')->nullable();
            $table->string('local_agent_location')->nullable();
            $table->string('foreign_agent_company')->nullable();
            $table->string('foreign_agent_name')->nullable();
            $table->string('foreign_agent_phone')->nullable();
            $table->string('foreign_agent_location')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
