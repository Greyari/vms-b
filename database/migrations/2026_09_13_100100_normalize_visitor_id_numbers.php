<?php

use App\Support\IdentityNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stored identity numbers take the spelling IdentityNumber::normalize()
 * produces. A row whose normalized value already belongs to another visitor of
 * the same id_type is left as it is and logged: merging two visitors is a
 * decision for a person (or the legacy importer), not for a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('visitors')
            ->select(['id', 'id_type', 'id_number'])
            ->chunkById(500, function ($visitors): void {
                foreach ($visitors as $visitor) {
                    $normalized = IdentityNumber::normalize($visitor->id_number);

                    if ($normalized === '' || $normalized === $visitor->id_number) {
                        continue;
                    }

                    $taken = DB::table('visitors')
                        ->where('id_type', $visitor->id_type)
                        ->where('id_number', $normalized)
                        ->where('id', '!=', $visitor->id)
                        ->exists();

                    if ($taken) {
                        Log::warning('Visitor id_number not normalized: the normalized value belongs to another visitor.', [
                            'visitor_id' => $visitor->id,
                        ]);

                        continue;
                    }

                    DB::table('visitors')->where('id', $visitor->id)->update(['id_number' => $normalized]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible on purpose: the original punctuation was never recorded,
        // and putting it back would only recreate duplicate spellings.
    }
};
