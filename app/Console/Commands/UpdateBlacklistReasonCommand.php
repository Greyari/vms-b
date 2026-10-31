<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpdateBlacklistReasonCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:update-blacklist-reason
                            {--from=Blacklisted by ASL Management : Teks lama yang ingin diganti}
                            {--to=blok by management : Teks baru pengganti}
                            {--dry-run : Pratinjau perubahan tanpa mengubah data di database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Memperbarui kalimat alasan blacklist di database produksi (misal: Blacklisted by ASL Management -> blok by management)';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');

        if (trim($from) === '') {
            $this->error('Parameter --from tidak boleh kosong.');

            return self::FAILURE;
        }

        $this->info('=== PEMBARUAN ALASAN BLACKLIST ===');
        $this->line("Teks target (lama) : <comment>{$from}</comment>");
        $this->line("Teks baru          : <info>{$to}</info>");
        if ($dryRun) {
            $this->warn('[MODE DRY-RUN] Data tidak akan disimpan ke database.');
        }
        $this->newLine();

        $stats = [
            'blacklists_offences' => 0,
            'blacklists_remark' => 0,
            'visitors_blacklist_reason' => 0,
            'visitors_offences' => 0,
            'visitors_remark' => 0,
        ];

        DB::beginTransaction();
        try {
            // 1. Tabel blacklists
            if (Schema::hasTable('blacklists')) {
                // Kolom offences
                if (Schema::hasColumn('blacklists', 'offences')) {
                    $query = DB::table('blacklists')->where('offences', 'like', "%{$from}%");
                    $stats['blacklists_offences'] = $query->count();
                    if (! $dryRun && $stats['blacklists_offences'] > 0) {
                        DB::table('blacklists')
                            ->where('offences', 'like', "%{$from}%")
                            ->update([
                                'offences' => DB::raw('REPLACE(offences, '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')'),
                            ]);
                    }
                }

                // Kolom remark
                if (Schema::hasColumn('blacklists', 'remark')) {
                    $query = DB::table('blacklists')->where('remark', 'like', "%{$from}%");
                    $stats['blacklists_remark'] = $query->count();
                    if (! $dryRun && $stats['blacklists_remark'] > 0) {
                        DB::table('blacklists')
                            ->where('remark', 'like', "%{$from}%")
                            ->update([
                                'remark' => DB::raw('REPLACE(remark, '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')'),
                            ]);
                    }
                }
            }

            // 2. Tabel visitors
            if (Schema::hasTable('visitors')) {
                // Kolom blacklist_reason
                if (Schema::hasColumn('visitors', 'blacklist_reason')) {
                    $query = DB::table('visitors')->where('blacklist_reason', 'like', "%{$from}%");
                    $stats['visitors_blacklist_reason'] = $query->count();
                    if (! $dryRun && $stats['visitors_blacklist_reason'] > 0) {
                        DB::table('visitors')
                            ->where('blacklist_reason', 'like', "%{$from}%")
                            ->update([
                                'blacklist_reason' => DB::raw('REPLACE(blacklist_reason, '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')'),
                            ]);
                    }
                }

                // Kolom offences (jika ada)
                if (Schema::hasColumn('visitors', 'offences')) {
                    $query = DB::table('visitors')->where('offences', 'like', "%{$from}%");
                    $stats['visitors_offences'] = $query->count();
                    if (! $dryRun && $stats['visitors_offences'] > 0) {
                        DB::table('visitors')
                            ->where('offences', 'like', "%{$from}%")
                            ->update([
                                'offences' => DB::raw('REPLACE(offences, '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')'),
                            ]);
                    }
                }

                // Kolom remark (jika ada)
                if (Schema::hasColumn('visitors', 'remark')) {
                    $query = DB::table('visitors')->where('remark', 'like', "%{$from}%");
                    $stats['visitors_remark'] = $query->count();
                    if (! $dryRun && $stats['visitors_remark'] > 0) {
                        DB::table('visitors')
                            ->where('remark', 'like', "%{$from}%")
                            ->update([
                                'remark' => DB::raw('REPLACE(remark, '.DB::getPdo()->quote($from).', '.DB::getPdo()->quote($to).')'),
                            ]);
                    }
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            $totalAffected = array_sum($stats);

            $this->table(
                ['Tabel & Kolom Target', 'Jumlah Baris Ditemukan / Diubah'],
                [
                    ['blacklists.offences', $stats['blacklists_offences']],
                    ['blacklists.remark', $stats['blacklists_remark']],
                    ['visitors.blacklist_reason', $stats['visitors_blacklist_reason']],
                    ['visitors.offences', $stats['visitors_offences']],
                    ['visitors.remark', $stats['visitors_remark']],
                ]
            );

            if ($dryRun) {
                $this->info("Simulasi selesai. Total {$totalAffected} baris cocok dan siap diubah.");
                $this->comment('Jalankan tanpa opsi --dry-run untuk menerapkan perubahan secara permanen.');
            } else {
                $this->info("Sukses! Total {$totalAffected} baris berhasil diperbarui ke database.");
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Gagal memperbarui data: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
