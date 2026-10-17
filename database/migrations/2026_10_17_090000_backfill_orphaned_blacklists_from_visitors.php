<?php

use App\Services\BlacklistService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('blacklists') || ! Schema::hasTable('visitors')) {
            return;
        }

        app(BlacklistService::class)->repairOrphanedBlacklists(logActivity: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Non-destructive data repair migration; do not delete valid blacklist records on rollback.
    }
};
