<?php

use App\Support\Username;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stored usernames take the spelling App\Support\Username::normalize()
 * produces. A row whose upper-cased value already belongs to another account
 * is left as it is and logged as an error: login upper-cases what is typed, so
 * that account cannot sign in by username until an administrator renames one
 * of the two. Deciding which account keeps the name is not a migration's job.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotNull('username')
            ->select(['id', 'username'])
            ->chunkById(500, function ($users): void {
                foreach ($users as $user) {
                    $normalized = Username::normalize($user->username);

                    if ($normalized === $user->username) {
                        continue;
                    }

                    $holderId = $normalized === null ? null : DB::table('users')
                        ->where('username', $normalized)
                        ->where('id', '!=', $user->id)
                        ->value('id');

                    if ($holderId !== null) {
                        Log::error('Username not normalized: another account already holds the upper-cased spelling, so this account cannot sign in by username until an administrator renames one of the two.', [
                            'user_id' => $user->id,
                            'conflicting_user_id' => $holderId,
                        ]);

                        continue;
                    }

                    DB::table('users')->where('id', $user->id)->update(['username' => $normalized]);
                }
            });
    }

    public function down(): void
    {
        // Irreversible on purpose: the original letter case was never recorded.
    }
};
