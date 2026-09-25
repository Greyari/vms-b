<?php

namespace Tests\Feature\Deployment;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WindowsPackageTest extends TestCase
{
    private string $toolsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolsDir = dirname(base_path()).DIRECTORY_SEPARATOR.'tools'.DIRECTORY_SEPARATOR.'windows';
    }

    public function test_apache_configuration_template_exists_and_has_required_directives(): void
    {
        $confPath = $this->toolsDir.DIRECTORY_SEPARATOR.'apache'.DIRECTORY_SEPARATOR.'httpd-vms.conf';
        $this->assertTrue(File::exists($confPath), "Apache configuration file not found at: {$confPath}");

        $content = File::get($confPath);

        // 1. Port 80 redirect to HTTPS
        $this->assertStringContainsString('<VirtualHost *:80>', $content);
        $this->assertStringContainsString('Redirect permanent / https://', $content);

        // 2. Port 443 VirtualHost and SSL Directives
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
}
