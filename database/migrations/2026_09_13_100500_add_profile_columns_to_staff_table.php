<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The host profile VMS-old kept (ESSStaffToVisit). VMS-old has no department,
 * so department becomes optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table): void {
            $table->char('gender', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('company')->nullable();
            $table->string('address')->nullable();
            $table->string('occupation')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('department')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('staff')->whereNull('department')->update(['department' => '']);

        Schema::table('staff', function (Blueprint $table): void {
            $table->string('department')->nullable(false)->change();
            $table->dropColumn(['gender', 'birth_date', 'company', 'address', 'occupation', 'is_active']);
        });
    }
};
