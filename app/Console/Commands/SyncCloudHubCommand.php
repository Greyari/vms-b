<?php

namespace App\Console\Commands;

use App\Services\CloudHub\CloudHubClient;
use App\Services\CloudHub\CloudHubSync;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * One sync cycle with the cloud hub (hybrid spec §5.4). Task Scheduler runs it every minute
 * (tools/windows/setup-sync-task.ps1, decision OPS-7). It always exits 0: a hub problem is recorded
 * in sync_state_logs and must never look like a broken gate.
 *
 * A lock keeps one cycle at a time. A scheduled run that finds the lock taken simply skips;
 * --force (after a revocation, decision SEC-7) waits up to 30 seconds for it instead.
 */
class SyncCloudHubCommand extends Command
{
    public const LOCK = 'vms:sync-cloud-hub';

    protected $signature = 'vms:sync-cloud-hub {--force : Wait for a running cycle instead of skipping}';

    protected $description = 'Push the global blacklist outbox and pull blacklist changes and pre-registrations from the cloud hub';

    public function handle(): int
    {
        if (! config('vms.sync.enabled')) {
            $this->line('Cloud hub sync is switched off (VMS_SYNC_ENABLED=false).');

            return self::SUCCESS;
        }

        // deploy.ps1 puts the app down before it migrates and swaps the release folder: no cycle may write
        // to a half-migrated database or keep the app folder busy. The next minute after `artisan up` syncs.
        if ($this->laravel->isDownForMaintenance()) {
            $this->line('The gate is in maintenance mode (deploy running); skipped.');

            return self::SUCCESS;
        }

        if (($problem = CloudHubSync::configurationProblem()) !== null) {
            CloudHubSync::recordProblem($problem);
            $this->error($problem);

            return self::SUCCESS;
        }

        $lock = Cache::lock(self::LOCK, 300);

        try {
            $acquired = $this->option('force') ? $lock->block(30) : $lock->get();
        } catch (LockTimeoutException) {
            $acquired = false;
        }

        if (! $acquired) {
            $this->line('Another sync cycle is running; skipped.');

            return self::SUCCESS;
        }

        try {
            (new CloudHubSync(CloudHubClient::fromConfig()))->run();
            $this->info('Sync cycle finished.');
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
