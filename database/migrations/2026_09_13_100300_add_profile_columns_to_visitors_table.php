<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The visitor profile VMS-old kept (ESSVisitorMaster) and the new schema did not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->unsignedBigInteger('visitor_no')->nullable()->unique();
            $table->string('visitor_type', 20)->default('visitor')->index();
            $table->char('gender', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('birth_place')->nullable();
            $table->string('nationality')->nullable();
            $table->string('occupation')->nullable();
            $table->string('phone')->nullable();
            $table->date('id_issued_at')->nullable();
            $table->date('id_expires_at')->nullable();
            $table->boolean('id_lifetime')->default(false);
            $table->foreignUuid('vessel_id')->nullable()->index()->constrained('locations')->nullOnDelete();
            $table->string('certificate')->nullable();
            $table->string('certificate_no')->nullable();
            $table->string('seaman_book_no')->nullable();
            $table->date('seaman_book_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table): void {
            $table->dropForeign(['vessel_id']);
            $table->dropUnique(['visitor_no']);
            $table->dropIndex(['visitor_type']);
            $table->dropIndex(['vessel_id']);
            $table->dropColumn([
                'visitor_no', 'visitor_type', 'gender', 'birth_date', 'birth_place', 'nationality',
                'occupation', 'phone', 'id_issued_at', 'id_expires_at', 'id_lifetime', 'vessel_id',
                'certificate', 'certificate_no', 'seaman_book_no', 'seaman_book_expires_at',
            ]);
        });
    }
};
