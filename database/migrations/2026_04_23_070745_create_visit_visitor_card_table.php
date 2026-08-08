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
        Schema::create('visit_visitor_card', function (Blueprint $table) {
            // The VisitVisitorCard pivot model generates UUIDv7 ids, and
            // visits + visitor_cards use UUID primary keys — the original
            // bigint columns could never work on a strict engine (PG rejects
            // both the FK types and the UUID id values).
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('visitor_card_id')->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_visitor_card');
    }
};
