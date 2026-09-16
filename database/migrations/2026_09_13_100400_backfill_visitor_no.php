<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Visitors that existed before visitor_no get numbers from the floor upward,
 * oldest first — the same range the application uses for every visitor it
 * creates. Numbers below the floor stay reserved for VMS-old's VisitorID.
 */
return new class extends Migration
{
    public function up(): void
    {
        $floor = (int) config('vms.visitor_no_floor');
        $highest = DB::table('visitors')->where('visitor_no', '>=', $floor)->max('visitor_no');
        $next = $highest === null ? $floor : ((int) $highest) + 1;

        $ids = DB::table('visitors')
            ->whereNull('visitor_no')
            ->orderBy('created_at')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            DB::table('visitors')->where('id', $id)->update(['visitor_no' => $next++]);
        }
    }

    public function down(): void
    {
        // The column and its values are dropped by the previous migration's down().
    }
};
