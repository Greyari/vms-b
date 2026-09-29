<?php

use Illuminate\Support\Facades\File;

/*
 * Roadmap step 9 (decisions OPS-6, SEC-8, SEC-8-FREE, OPS-3): the on-prem deploy reaches a PC inside a
 * yard's LAN, so it may start only by hand, only from production, never for a pull request, and it runs
 * the deploy script of the very release it verified.
 */

beforeEach(function () {
    $this->workflow = File::get(base_path('.github/workflows/deploy-onprem.yml'));
});

it('starts only by hand, never on push, tag, schedule or pull request', function () {
    preg_match('/^on:\n(.*?)\n\S/ms', $this->workflow, $trigger);

    expect($trigger[1] ?? '')->toContain('workflow_dispatch:')
        ->not->toContain('push:')
        ->not->toContain('pull_request')
        ->not->toContain('schedule:')
        ->not->toContain('tags:');
});

it('deploys only production, and only a commit whose Quality run passed', function () {
    expect(substr_count($this->workflow, "if: github.ref == 'refs/heads/production'"))->toBe(2)
        ->and($this->workflow)->toContain('--workflow quality.yml --commit "${{ github.sha }}"')
        ->and($this->workflow)->toContain("if (\$conclusion -ne 'success')");
});

it('lets the operator choose the yard, and runs on that yard\'s own runner only', function () {
    expect($this->workflow)->toContain('options: [cis, asl]')
        ->and($this->workflow)->toContain('runs-on: [self-hosted, windows, "yard-${{ inputs.yard }}"]')
        ->and($this->workflow)->toContain('group: deploy-onprem-${{ inputs.yard }}');
});

it('builds with the same script as a laptop release, reading the SPA with the read-only deploy key', function () {
    expect($this->workflow)->toContain('tools\windows\build-release.ps1')
        ->and($this->workflow)->toContain('ssh-key: ${{ secrets.VMS_WEB_REACT_DEPLOY_KEY }}') // OPS-3-KEY
        ->and($this->workflow)->not->toContain('ORG_REPO_READ_TOKEN')
        ->and($this->workflow)->toContain('repository: INIT-ASL/VMS-web-react')
        ->and($this->workflow)->not->toContain('id-token: write');
});

it('runs the yard steps with a process-scoped execution policy, never relying on the PC\'s own', function () {
    // CIS, 2026-09-30: Windows 11 ships with the Restricted policy, and the runner's plain `shell: powershell`
    // dot-sources a .ps1 file, so every yard step died with "running scripts is disabled on this system".
    $deployJob = substr($this->workflow, strpos($this->workflow, "\n  deploy:"));
    $bypass = 'shell: powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "& \'{0}\'"';

    expect(substr_count($deployJob, $bypass))->toBe(2)
        ->and(preg_match('/^\s+shell: powershell$/m', $deployJob))->toBe(0);
});

it('checks the ZIP hash and runs that release\'s own deploy.ps1 from outside the app folder', function () {
    expect($this->workflow)->toContain('Get-FileHash -LiteralPath $zip -Algorithm SHA256')
        ->and($this->workflow)->toContain('if ($actual -ne $manifest.sha256)')
        ->and($this->workflow)->toContain("Join-Path \$unpack 'tools\\windows\\deploy.ps1'")
        ->and($this->workflow)->toContain('$env:RUNNER_TEMP');
});
