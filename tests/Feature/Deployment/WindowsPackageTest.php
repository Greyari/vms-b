<?php

namespace Tests\Feature\Deployment;

use Illuminate\Support\Facades\File;
use Tests\Support\PowerShell;
use Tests\TestCase;

class WindowsPackageTest extends TestCase
{
    private string $toolsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolsDir = base_path('tools'.DIRECTORY_SEPARATOR.'windows');
    }

    public function test_apache_configuration_template_exists_and_has_required_directives(): void
    {
        $confPath = $this->toolsDir.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'httpd-vms.conf';
        $this->assertTrue(File::exists($confPath), "Apache configuration file not found at: {$confPath}");

        $content = File::get($confPath);

        // 1. Port 80 redirect to HTTPS
        $this->assertStringContainsString('<VirtualHost *:80>', $content);
        $this->assertStringContainsString('Redirect permanent / https://', $content);

        // 2. Port 443 VirtualHost and SSL Directives. Apache Lounge's httpd.conf only has `Listen 80`
        //    (its `Listen 443` lives in extra/httpd-ssl.conf, which the install guide does not include),
        //    so without this line nothing ever answers on 443 (CIS server, 2026-09-30).
        $this->assertMatchesRegularExpression('/^Listen 443$/m', $content);
        $this->assertStringContainsString('<VirtualHost *:443>', $content);
        $this->assertStringContainsString('SSLEngine on', $content);
        $this->assertStringContainsString('SSLCertificateFile "C:/VMS/certs/vms-server.crt"', $content);
        $this->assertStringContainsString('SSLCertificateKeyFile "C:/VMS/certs/vms-server.key"', $content);
        $this->assertStringContainsString('SSLCACertificateFile "C:/VMS/certs/vms-root-ca.crt"', $content);

        // 3. DocumentRoot and AllowOverride All
        $this->assertStringContainsString('DocumentRoot "C:/VMS/app/public"', $content);
        $this->assertStringContainsString('<Directory "C:/VMS/app/public">', $content);
        $this->assertStringContainsString('AllowOverride All', $content);

        // 4. PHP 8.4 Module loading
        $this->assertStringContainsString('php8apache2_4.dll', $content);
        $this->assertStringContainsString('PHPIniDir "C:/VMS/php"', $content);
        $this->assertStringContainsString('AddType application/x-httpd-php .php', $content);

        // 5. Security Headers
        $this->assertStringContainsString('Header always set X-Content-Type-Options "nosniff"', $content);
        $this->assertStringContainsString('Header always set X-Frame-Options "DENY"', $content);
        $this->assertStringContainsString('Header always set Referrer-Policy "same-origin"', $content);

        // 6. No-cache directive on index.html
        $this->assertStringContainsString('<Files "index.html">', $content);
        $this->assertStringContainsString('Cache-Control "no-store, no-cache, must-revalidate"', $content);
    }

    public function test_php_production_ini_exists_and_has_required_directives(): void
    {
        $iniPath = $this->toolsDir.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php-production.ini';
        $this->assertTrue(File::exists($iniPath), "PHP production ini file not found at: {$iniPath}");

        $content = File::get($iniPath);

        // Memory limit & timezone
        $this->assertStringContainsString('memory_limit = 512M', $content);
        $this->assertStringContainsString('date.timezone = Asia/Jakarta', $content);

        // Opcache
        $this->assertStringContainsString('opcache.enable = 1', $content);

        // Required extensions
        $requiredExtensions = [
            'extension=pdo_pgsql',
            'extension=pgsql',
            'extension=intl',
            'extension=zip',
            'extension=gd',
            'extension=mbstring',
            'extension=openssl',
            'extension=fileinfo',
        ];

        foreach ($requiredExtensions as $ext) {
            $this->assertStringContainsString($ext, $content, "Missing required PHP extension: {$ext}");
        }
    }

    public function test_setup_ca_script_exists_and_has_valid_syntax(): void
    {
        $scriptPath = $this->toolsDir.DIRECTORY_SEPARATOR.'setup-ca.ps1';
        $this->assertTrue(File::exists($scriptPath), "setup-ca.ps1 not found at: {$scriptPath}");

        $content = File::get($scriptPath);
        $this->assertStringContainsString('param(', $content);
        $this->assertStringContainsString('$ServerIp', $content);
        $this->assertStringContainsString('$Hostname', $content);
        $this->assertStringContainsString('$OutputDir', $content);
        $this->assertStringContainsString('vms-root-ca.crt', $content);
        $this->assertStringContainsString('vms-server.crt', $content);
        $this->assertStringContainsString('subjectAltName', $content);
        $this->assertStringContainsString('certutil', $content);
    }

    /**
     * CIS server, 2026-09-30: the root CA was constrained to `.asl.local` whatever -Hostname said, so
     * `-Hostname vms.cis.local` produced a server certificate its own root forbids ("permitted subtree
     * violation") and Edge refused it as NET::ERR_CERT_INVALID. This runs the real script with openssl.
     */
    public function test_setup_ca_issues_a_chain_its_own_root_accepts_for_any_yard_host(): void
    {
        $binary = PowerShell::binary();
        $openssl = $this->openSslBinary();
        if ($binary === null || $openssl === null) {
            $this->markTestSkipped('Needs PowerShell and openssl.');
        }

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vms-ca-'.uniqid();
        try {
            $command = escapeshellarg($binary).' -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '
                .escapeshellarg($this->toolsDir.DIRECTORY_SEPARATOR.'setup-ca.ps1')
                .' -ServerIp 192.168.50.100 -Hostname vms.cis.local -Organization '.escapeshellarg('PT Cemara Intan Shipyard')
                .' -OutputDir '.escapeshellarg($dir).' -OpenSslPath '.escapeshellarg($openssl).' -Force 2>&1';
            exec($command, $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));

            $root = $dir.DIRECTORY_SEPARATOR.'vms-root-ca.crt';
            $server = $dir.DIRECTORY_SEPARATOR.'vms-server.crt';
            exec(escapeshellarg($openssl).' verify -CAfile '.escapeshellarg($root).' '.escapeshellarg($server).' 2>&1', $verify, $verifyCode);
            $this->assertSame(0, $verifyCode, implode("\n", $verify));

            exec(escapeshellarg($openssl).' x509 -in '.escapeshellarg($root).' -noout -subject -ext nameConstraints 2>&1', $rootText);
            $rootText = implode("\n", $rootText);
            $this->assertStringContainsString('DNS:vms.cis.local', $rootText);
            $this->assertStringNotContainsString('asl.local', $rootText);
            $this->assertStringContainsString('PT Cemara Intan Shipyard', $rootText);

            $bat = File::get($dir.DIRECTORY_SEPARATOR.'import-root-ca.bat');
            $this->assertStringContainsString('certutil -delstore Root "VMS Internal Root CA"', $bat);
            $this->assertStringContainsString('certutil -addstore -f Root', $bat);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function openSslBinary(): ?string
    {
        $candidates = array_filter([getenv('OPENSSL_BIN') ?: null, 'openssl']);
        foreach ($candidates as $candidate) {
            $probe = PHP_OS_FAMILY === 'Windows' ? "where {$candidate} 2>NUL" : "command -v {$candidate} 2>/dev/null";
            $found = [];
            exec($probe, $found, $code);
            if ($code === 0 && isset($found[0])) {
                return trim($found[0]);
            }
        }
        foreach (['C:\Program Files\Git\mingw64\bin\openssl.exe', 'C:\VMS\Apache24\bin\openssl.exe'] as $path) {
            if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public function test_build_release_script_exists_and_has_valid_syntax(): void
    {
        $scriptPath = $this->toolsDir.DIRECTORY_SEPARATOR.'build-release.ps1';
        $this->assertTrue(File::exists($scriptPath), "build-release.ps1 not found at: {$scriptPath}");

        $content = File::get($scriptPath);
        $this->assertStringContainsString('param(', $content);
        $this->assertStringContainsString('$OutputDir', $content);
        $this->assertStringContainsString('$Version', $content);
        $this->assertStringContainsString('VITE_API_BASE_URL', $content);
        $this->assertStringContainsString('composer install', $content);
        $this->assertStringContainsString('--no-dev', $content);
        $this->assertStringContainsString('Compress-Archive', $content);
    }

    public function test_deploy_script_exists_and_has_valid_syntax(): void
    {
        $scriptPath = $this->toolsDir.DIRECTORY_SEPARATOR.'deploy.ps1';
        $this->assertTrue(File::exists($scriptPath), "deploy.ps1 not found at: {$scriptPath}");

        $content = File::get($scriptPath);
        $this->assertStringContainsString('param(', $content);
        $this->assertStringContainsString('$ReleaseZip', $content);
        $this->assertStringContainsString('$Rollback', $content);
        $this->assertStringContainsString('$AppDir', $content);
        $this->assertStringContainsString('artisan down', $content);
        $this->assertStringContainsString('pg_dump', $content);
        $this->assertStringContainsString('artisan migrate', $content);
        $this->assertStringContainsString('VMS-Apache', $content);
        $this->assertStringContainsString('health-db', $content);
        $this->assertStringContainsString('artisan up', $content);
    }

    public function test_backup_and_task_scheduler_scripts_exist_and_have_valid_syntax(): void
    {
        $backupScript = $this->toolsDir.DIRECTORY_SEPARATOR.'backup-db.ps1';
        $taskScript = $this->toolsDir.DIRECTORY_SEPARATOR.'setup-backup-task.ps1';

        $this->assertTrue(File::exists($backupScript), "backup-db.ps1 not found at: {$backupScript}");
        $this->assertTrue(File::exists($taskScript), "setup-backup-task.ps1 not found at: {$taskScript}");

        $backupContent = File::get($backupScript);
        $this->assertStringContainsString('param(', $backupContent);
        $this->assertStringContainsString('pg_dump', $backupContent);
        $this->assertStringContainsString('$OutputDir', $backupContent);
        $this->assertStringContainsString('$RetentionDays', $backupContent);
        $this->assertStringContainsString('$SecondaryDir', $backupContent);

        $taskContent = File::get($taskScript);
        $this->assertStringContainsString('param(', $taskContent);
        $this->assertStringContainsString('02:00', $taskContent);
        $this->assertStringContainsString('SYSTEM', $taskContent);
    }

    /** Decision OPS-7: a valid repetition duration, one instance at a time, no SYSTEM, no interactive account. */
    public function test_sync_task_script_follows_ops_7(): void
    {
        $content = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'setup-sync-task.ps1');

        $this->assertStringContainsString('vms:sync-cloud-hub', $content);
        $this->assertStringContainsString('-MultipleInstances IgnoreNew', $content);
        $this->assertStringContainsString('-RepetitionInterval (New-TimeSpan -Minutes 1)', $content);
        $this->assertStringContainsString('-RepetitionDuration (New-TimeSpan -Days 1)', $content);
        $this->assertStringContainsString('LOCAL SERVICE', $content);
        $this->assertStringNotContainsString('MaxValue', $content);
        $this->assertStringNotContainsString('SYSTEM', $content);
    }

    /** Decision OPS-8: the server itself syncs to internet NTP, hourly, and may correct a large offset. */
    public function test_time_sync_script_follows_ops_8(): void
    {
        $content = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'setup-time-sync.ps1');

        $this->assertStringContainsString('/syncfromflags:manual', $content);
        $this->assertStringContainsString('SpecialPollInterval', $content);
        $this->assertStringContainsString('MaxPosPhaseCorrection', $content);
        $this->assertStringContainsString('MaxNegPhaseCorrection', $content);
    }

    public function test_install_guide_exists_and_contains_all_checklists(): void
    {
        $guidePath = $this->toolsDir.DIRECTORY_SEPARATOR.'INSTALL-GUIDE.md';
        $this->assertTrue(File::exists($guidePath), "INSTALL-GUIDE.md not found at: {$guidePath}");

        $content = File::get($guidePath);
        $this->assertStringContainsString('PostgreSQL 18', $content);
        $this->assertStringContainsString('lc_messages', $content);
        $this->assertStringContainsString('Apache 2.4', $content);
        $this->assertStringContainsString('VMS-Apache', $content);
        $this->assertStringContainsString('PHP 8.4', $content);
        $this->assertStringContainsString('setup-ca.ps1', $content);
        $this->assertStringContainsString('certutil', $content);
        $this->assertStringContainsString('TRUSTED_PROXIES', $content);
        $this->assertStringContainsString('deploy.ps1', $content);
        $this->assertStringContainsString('setup-backup-task.ps1', $content);
        $this->assertStringContainsString('NTP', $content);
        $this->assertStringContainsString('Checklist Penerimaan', $content);
    }

    public function test_every_powershell_script_in_the_package_parses(): void
    {
        $binary = PowerShell::binary();
        if ($binary === null) {
            if (getenv('REQUIRE_POWERSHELL') === '1') {
                $this->fail('REQUIRE_POWERSHELL=1 but neither pwsh nor powershell is on PATH.');
            }
            $this->markTestSkipped('Neither pwsh nor powershell is on PATH.');
        }

        $scripts = glob($this->toolsDir.DIRECTORY_SEPARATOR.'*.ps1');
        $this->assertNotEmpty($scripts, "No .ps1 files under {$this->toolsDir}");

        foreach ($scripts as $script) {
            $this->assertSame([], PowerShell::parseErrors($binary, $script), basename($script).' has PowerShell syntax errors');
        }
    }

    public function test_build_release_takes_explicit_backend_and_frontend_folders(): void
    {
        $content = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'build-release.ps1');
        $this->assertStringContainsString('$BackendDir', $content);
        $this->assertStringContainsString('$FrontendDir', $content);
        $this->assertStringNotContainsString('Join-Path $repoRoot "VMS-backend-laravel"', $content);
    }

    /** Decision OPS-4: staging only, SPA built once into staging, deploy.ps1 travels inside the ZIP. */
    public function test_build_release_never_touches_the_checkout_and_ships_its_deploy_script(): void
    {
        $content = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'build-release.ps1');

        $this->assertStringContainsString('Push-Location $staging', $content);
        $this->assertStringContainsString("Join-Path \$staging 'public'", $content);
        $this->assertStringContainsString("'tools'", $content);
        $this->assertStringContainsString('backend_commit', $content);
        $this->assertStringNotContainsString('Join-Path $backendDir "public"', $content);
        $this->assertStringNotContainsString("Join-Path \$backendDir 'public'", $content);
    }

    /** Decision OPS-5 (a-f): own dump required, retried probe, checked restart, folder swap, admin role only for dumps. */
    public function test_deploy_script_closes_the_ops_5_gaps(): void
    {
        $content = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'deploy.ps1');

        $this->assertStringContainsString('Restore-PreviousRelease $previousDir $dump', $content);        // (a) + (b)
        $this->assertStringContainsString('(Get-Item -LiteralPath $dump).Length -eq 0', $content);         // (b)
        $this->assertStringNotContainsString('Sort-Object LastWriteTime', $content);                       // (b) never "latest dump"
        $this->assertStringNotContainsString('SkipDbBackup', $content);                                    // (b)
        $this->assertStringContainsString('$ProbeAttempts = 6', $content);                                 // (c)
        $this->assertStringContainsString("WaitForStatus('Running'", $content);                            // (d)
        $this->assertStringNotContainsString('Restart-Service -Name "VMS-Apache" -ErrorAction SilentlyContinue', $content);
        $this->assertStringContainsString('previous-$stamp', $content);                                    // (e)
        $this->assertStringContainsString('Move-WithRetry $newDir $AppDir', $content);                      // (e)
        $this->assertStringContainsString('$DbAdminUser', $content);                                        // (f)
        $this->assertStringContainsString("'-w', '-U', \$DbAdminUser", $content);                          // (f) pgpass, never a password
        $this->assertStringContainsString("ErrorActionPreference = 'Continue'", $content);                 // stderr of php/pg_* is not fatal

        // CIS, 2026-09-30: the running app's storage\ had no framework\ (a first install from a zipped staging
        // folder), so `artisan down` and `view:cache` failed and the deploy rolled back. storage\ travels between
        // releases, so the skeleton is re-created before anything runs, and a failed `down` stops the deploy.
        $this->assertStringContainsString("Initialize-Storage (Join-Path \$AppDir 'storage')", $content);
        $this->assertStringContainsString("'framework\\views'", $content);
        $this->assertStringContainsString('php artisan down failed - deploy stopped, nothing changed.', $content);

        // CIS, 2026-09-30: under Windows PowerShell 5.1 the `ServerCertificateValidationCallback = { $true }`
        // script block runs on a thread without a runspace, every probe failed with "An unexpected error occurred
        // on a send" and a healthy deploy was rolled back. The probe uses curl.exe -k (built into Windows 10/11).
        $this->assertStringNotContainsString('ServerCertificateValidationCallback', $content);
        $this->assertStringContainsString("'curl.exe'", $content);
        $this->assertStringContainsString("'-k'", $content);
        $this->assertStringContainsString('--if-exists', $content);
    }

    /**
     * Decision OPS-5f: dumps and restores never prompt (a scheduled task has no terminal) and read their
     * passwords from one locked-down pgpass file; a rollback restore keeps vms_app as owner of its tables.
     */
    public function test_database_credentials_come_from_the_pgpass_file_only(): void
    {
        $pgpass = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'setup-pgpass.ps1');
        $deploy = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'deploy.ps1');
        $backup = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'backup-db.ps1');

        $this->assertStringContainsString('Read-Host -AsSecureString', $pgpass);
        $this->assertStringContainsString('/inheritance:r', $pgpass);
        $this->assertStringContainsString('UTF8Encoding $false', $pgpass); // libpq cannot read a BOM

        foreach ([$deploy, $backup] as $script) {
            $this->assertStringContainsString('C:\VMS\secrets\pgpass.conf', $script);
            $this->assertStringContainsString('$env:PGPASSFILE', $script);
            $this->assertStringNotContainsString('PGPASSWORD', $script);
        }
        $this->assertStringContainsString('-Fc -w -U $DbUser', $backup);
        $this->assertStringNotContainsString('cmd /c', $backup); // breaks on "C:\Program Files\..."
        $this->assertStringNotContainsString("'--no-owner'", $deploy); // restored tables stay vms_app's
    }

    /**
     * Decisions OPS-6 + SEC-8: the runner service logs on as a dedicated non-admin account with only what
     * deploy.ps1 needs, and is registered on the backend repository, never on the organization.
     */
    public function test_runner_account_follows_ops_6_and_sec_8(): void
    {
        $script = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'setup-runner-account.ps1');

        $this->assertStringContainsString("\$Account = 'svc-vms-runner'", $script);
        $this->assertStringContainsString('Read-Host -AsSecureString', $script);          // no password on a command line
        $this->assertStringContainsString("SecurityIdentifier('S-1-5-32-544')", $script);  // refuses an administrator account
        $this->assertStringContainsString('(OI)(CI)M', $script);                          // C:\VMS
        $this->assertStringContainsString('(OI)(CI)R', $script);                          // C:\VMS\secrets, read only
        $this->assertStringContainsString('sc.exe sdset', $script);                       // start/stop VMS-Apache
        $this->assertStringContainsString('--url https://github.com/INIT-ASL/VMS-backend-laravel', $script);
        $this->assertStringContainsString('--labels yard-$Yard', $script);
        $this->assertStringNotContainsString('--url https://github.com/INIT-ASL ', $script);
        $this->assertStringNotContainsString('--windowslogonpassword', $script);
    }

    /** The sync task works inside C:\VMS\app every minute; deploy pauses it around the folder swap. */
    public function test_deploy_pauses_the_sync_task_around_the_swap(): void
    {
        $deploy = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'deploy.ps1');

        $this->assertStringContainsString("\$SyncTaskName = 'VMS-Cloud-Sync'", $deploy);
        $this->assertStringContainsString('Disable-ScheduledTask', $deploy);
        $this->assertSame(3, preg_match_all('/^\s*Resume-SyncTask\s*$/m', $deploy)); // success, rollback, failed swap
        $this->assertStringContainsString('Folder swap failed', $deploy); // a half-done swap is undone, never left down
        $this->assertStringContainsString('C:\VMS\app\tools\windows\backup-db.ps1', File::get($this->toolsDir.DIRECTORY_SEPARATOR.'setup-backup-task.ps1'));
    }

    /** The install guide carries no password and follows the hybrid decisions (OPS-5f, OPS-8, PE-7, BE-6). */
    public function test_install_guide_follows_the_hybrid_decisions(): void
    {
        $guide = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'INSTALL-GUIDE.md');

        $this->assertStringNotContainsString('vms_rahasia', $guide);
        $this->assertStringContainsString('setup-pgpass.ps1', $guide);
        $this->assertStringContainsString('setup-time-sync.ps1', $guide);
        $this->assertStringContainsString('VMS_YARD_CODE', $guide);
        $this->assertStringContainsString('VMS_COMPANY_NAME', $guide);
        $this->assertStringContainsString('public\branding', $guide);
        $this->assertStringContainsString('C:\VMS\tools\deploy.ps1', $guide);
        $this->assertStringNotContainsString('manualpeerlist:"192.168', $guide);
    }

    /** Decision PE-7: the yard logo stays on the yard server - never shipped, always carried across a deploy. */
    public function test_the_yard_logo_stays_on_the_server(): void
    {
        $build = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'build-release.ps1');
        $deploy = File::get($this->toolsDir.DIRECTORY_SEPARATOR.'deploy.ps1');

        $this->assertStringContainsString('Join-Path $staging \'public\branding\'', $build);
        $this->assertStringContainsString('Join-Path $AppDir \'public\branding\'', $deploy);
        $this->assertStringContainsString('Join-Path $newDir \'public\branding\'', $deploy);
        $this->assertStringContainsString('/public/branding', File::get(base_path('.gitignore')));
    }
}
