<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Visits checked out before checked_out_at existed kept only a clock time. The
 * checkout is taken as the first such time after the check-in: the same day,
 * or the next day when the clock time is earlier than the check-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('visits')
            ->select(['id', 'date', 'time_in', 'time_out'])
            ->whereNotNull('time_out')
            ->whereNull('checked_out_at')
            ->chunkById(500, function ($visits): void {
                foreach ($visits as $visit) {
                    $checkedOutAt = Carbon::parse(Carbon::parse($visit->date)->toDateString().' '.$visit->time_out);

                    if ($visit->time_out < $visit->time_in) {
                        $checkedOutAt->addDay();
                    }

                    DB::table('visits')->where('id', $visit->id)->update([
                        'checked_out_at' => $checkedOutAt->toDateTimeString(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Derived values; the column is dropped by the previous migration's down().
    }
};
