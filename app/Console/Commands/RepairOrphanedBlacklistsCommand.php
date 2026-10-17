<?php

namespace App\Console\Commands;

use App\Services\BlacklistService;
use Illuminate\Console\Command;

class RepairOrphanedBlacklistsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vms:repair-blacklists';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing active blacklists records for visitors with is_blacklisted = true from legacy imports';

    public function handle(BlacklistService $blacklistService): int
    {
        $this->info('Memeriksa sinkronisasi data antara tabel visitors dan blacklists...');

        $report = $blacklistService->repairOrphanedBlacklists(logActivity: true);

        $this->newLine();
        $this->info('=== LAPORAN PERBAIKAN DATA BLACKLIST (LEGACY IMPORT BACKFILL) ===');
        $this->table(
            ['Metrik', 'Jumlah'],
            [
                ['Record Blacklist Baru Dibuat (Diperbaiki)', $report['repaired_count']],
                ['Record Sudah Sinkron Sebelumnya', $report['already_synced_count']],
                ['Data Lengkap (Punya KTP/Badge & Perusahaan)', $report['complete_count']],
                ['Butuh Dilengkapi Manual (Tanpa KTP & Badge, atau Tanpa Perusahaan)', $report['needs_manual_completion_count']],
                ['  - Tanpa KTP Asli (Placeholder NOKTP)', $report['missing_real_ktp_count']],
                ['  - Tanpa KTP Asli SEKALIGUS Tanpa Kode Badge', $report['missing_both_ktp_and_badge_count']],
                ['  - Tanpa Nama Perusahaan (Company Kosong)', $report['missing_company_count']],
                ['  - Tanpa Catatan Tambahan (Remark Kosong - Opsional)', $report['missing_remark_count']],
                ['Flag Visitor Diperbaiki dari Blacklist Aktif', $report['visitor_flags_repaired_count']],
            ]
        );

        return self::SUCCESS;
    }
}
