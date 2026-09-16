<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source key → new id for every imported row, so re-running an import (or a
 * delta import on cutover night) updates rows instead of duplicating them.
 * Only the importer uses it; it may be dropped once the cutover is settled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source', 20);
            $table->string('entity', 40);
            $table->string('source_key');
            $table->uuid('target_id')->index();
            $table->timestamp('imported_at');
            $table->unique(['source', 'entity', 'source_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mappings');
    }
};
