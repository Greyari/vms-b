<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('blacklists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visitor_id')->nullable()->constrained('visitors')->nullOnDelete();
            $table->string('name');
            $table->string('badge_code')->nullable()->index();
            $table->string('id_number')->index();
            $table->text('offences');
            $table->date('blacklisted_at')->index();
            $table->text('remark')->nullable();
            $table->string('company')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('unblacklisted_at')->nullable();
            $table->timestamps();
        });

        // Migrate existing blacklisted visitors into the blacklists table
        if (Schema::hasTable('visitors')) {
            $existingBlacklisted = DB::table('visitors')
                ->where('is_blacklisted', true)
                ->get();

            foreach ($existingBlacklisted as $visitor) {
                $date = $visitor->blacklisted_at ?? (substr((string) ($visitor->created_at ?? now()), 0, 10));
                $offences = $visitor->offences ?: ($visitor->blacklist_reason ?: 'Blacklisted');

                DB::table('blacklists')->insert([
                    'id' => Str::uuid7()->toString(),
                    'visitor_id' => $visitor->id,
                    'name' => $visitor->name,
                    'badge_code' => $visitor->badge_code,
                    'id_number' => $visitor->id_number,
                    'offences' => $offences,
                    'blacklisted_at' => $date,
                    'remark' => $visitor->remark,
                    'company' => $visitor->company,
                    'is_active' => true,
                    'unblacklisted_at' => null,
                    'created_at' => $visitor->created_at ?? now(),
                    'updated_at' => $visitor->updated_at ?? now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blacklists');
    }
};
