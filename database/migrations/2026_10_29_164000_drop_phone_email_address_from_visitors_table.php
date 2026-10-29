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
        Schema::table('visitors', function (Blueprint $table) {
            $columnsToDrop = [];
            if (Schema::hasColumn('visitors', 'phone')) {
                $columnsToDrop[] = 'phone';
            }
            if (Schema::hasColumn('visitors', 'email')) {
                $columnsToDrop[] = 'email';
            }
            if (Schema::hasColumn('visitors', 'address')) {
                $columnsToDrop[] = 'address';
            }

            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visitors', function (Blueprint $table) {
            if (! Schema::hasColumn('visitors', 'phone')) {
                $table->string('phone')->nullable();
            }
            if (! Schema::hasColumn('visitors', 'email')) {
                $table->string('email')->nullable();
            }
            if (! Schema::hasColumn('visitors', 'address')) {
                $table->text('address')->nullable();
            }
        });
    }
};
