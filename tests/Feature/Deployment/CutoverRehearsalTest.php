<?php

namespace Tests\Feature\Deployment;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CutoverRehearsalTest extends TestCase
{
    protected string $toolsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->toolsDir = base_path('../tools/windows');
    }

    public function test_rehearse_cutover_script_exists_and_has_valid_syntax(): void
    {
        $scriptPath = $this->toolsDir.DIRECTORY_SEPARATOR.'rehearse-cutover.ps1';
        $this->assertTrue(File::exists($scriptPath), "rehearse-cutover.ps1 not found at: {$scriptPath}");

        $content = File::get($scriptPath);
        $this->assertStringContainsString('param(', $content);
        $this->assertStringContainsString('$Server', $content);
        $this->assertStringContainsString('$Database', $content);
        $this->assertStringContainsString('$OutputDir', $content);
        $this->assertStringContainsString('export-vms-old.ps1', $content);
        $this->assertStringContainsString('vms:import', $content);
        $this->assertStringContainsString('vms:import-verify', $content);
        $this->assertStringContainsString('-Since', $content);
        $this->assertStringContainsString('rehearsal-summary', $content);

        // Verify PowerShell syntax parser
        $escapedPath = addslashes($scriptPath);
        $command = "powershell -NoProfile -Command \"\$err = \$null; [System.Management.Automation.Language.Parser]::ParseFile('{$escapedPath}', [ref]\$null, [ref]\$err) | Out-Null; if (\$err) { exit 1 } else { exit 0 }\"";
        $exitCode = 1;
        $output = [];
        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, 'rehearse-cutover.ps1 has PowerShell syntax errors: '.implode("\n", $output));
    }

    public function test_audit_performance_script_exists_and_has_valid_syntax(): void
    {
        $scriptPath = $this->toolsDir.DIRECTORY_SEPARATOR.'audit-performance.ps1';
        $this->assertTrue(File::exists($scriptPath), "audit-performance.ps1 not found at: {$scriptPath}");

        $content = File::get($scriptPath);
        $this->assertStringContainsString('param(', $content);
        $this->assertStringContainsString('$BaseUrl', $content);
        $this->assertStringContainsString('$TestIdNumber', $content);
        $this->assertStringContainsString('$Month', $content);
        $this->assertStringContainsString('1000', $content); // SLA < 1000 ms search
        $this->assertStringContainsString('3000', $content); // SLA < 3000 ms monthly report
        $this->assertStringContainsString('30000', $content); // SLA < 30000 ms excel export
        $this->assertStringContainsString('/api/visitors/check-ktp', $content);
        $this->assertStringContainsString('/api/reports/visits', $content);

        // Verify PowerShell syntax parser
        $escapedPath = addslashes($scriptPath);
        $command = "powershell -NoProfile -Command \"\$err = \$null; [System.Management.Automation.Language.Parser]::ParseFile('{$escapedPath}', [ref]\$null, [ref]\$err) | Out-Null; if (\$err) { exit 1 } else { exit 0 }\"";
        $exitCode = 1;
        $output = [];
        exec($command, $output, $exitCode);
        $this->assertSame(0, $exitCode, 'audit-performance.ps1 has PowerShell syntax errors: '.implode("\n", $output));
    }

    public function test_cutover_runbook_exists_and_contains_required_sections(): void
    {
        $runbookPath = base_path('../docs/cutover-runbook.md');
        $this->assertTrue(File::exists($runbookPath), "cutover-runbook.md not found at: {$runbookPath}");

        $content = File::get($runbookPath);
        $this->assertStringContainsString('H-7', $content);
        $this->assertStringContainsString('H-1', $content);
        $this->assertStringContainsString('03:00', $content);
        $this->assertStringContainsString('05:00', $content);
        $this->assertStringContainsString('Go / No-Go', $content);
        $this->assertStringContainsString('03:30', $content);
        $this->assertStringContainsString('Rollback', $content);
        $this->assertStringContainsString('vms:import-verify', $content);
        $this->assertStringContainsString('14 akun', $content);
        $this->assertStringContainsString('Checklist Penerimaan', $content);
    }
}
