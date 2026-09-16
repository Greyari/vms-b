<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where the visitor went, what the visit looked like at check-in, and when the
 * visitor really left. checked_out_at carries the date: 42% of VMS-old
 * checkouts happen on a later day than the check-in, which time_out alone
 * cannot express.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->foreignUuid('location_id')->nullable()->index()->constrained('locations')->nullOnDelete();
            $table->string('location_name')->nullable();
            $table->string('host_name')->nullable();
            $table->string('visitor_company')->nullable();
            $table->timestamp('checked_out_at')->nullable()->index();
            $table->string('legacy_registration_no')->nullable()->index();
            $table->string('purpose')->nullable()->change();
        });

        // Deleting a staff member used to delete every visit that named them
        // (cascadeOnDelete). Visit history outlives the staff record: the link
        // is nulled instead, and host_name keeps who was visited.
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropForeign(['staff_id']);
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->uuid('staff_id')->nullable()->change();
            $table->foreign('staff_id')->references('id')->on('staff')->nullOnDelete();
        });
    }

    /**
     * staff_id and purpose are not made NOT NULL again: rows written after
     * up() may legitimately hold nulls.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table): void {
            $table->dropForeign(['staff_id']);
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
        });

        Schema::table('visits', function (Blueprint $table): void {
            $table->dropForeign(['location_id']);
            $table->dropIndex(['location_id']);
            $table->dropIndex(['checked_out_at']);
            $table->dropIndex(['legacy_registration_no']);
            $table->dropColumn([
                'location_id', 'location_name', 'host_name', 'visitor_company',
                'checked_out_at', 'legacy_registration_no',
            ]);
        });
    }
};
