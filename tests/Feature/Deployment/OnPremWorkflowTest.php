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

it('builds with the same script as a laptop release, reading the SPA with the read-only token', function () {
    expect($this->workflow)->toContain('tools\windows\build-release.ps1')
        ->and($this->workflow)->toContain('token: ${{ secrets.ORG_REPO_READ_TOKEN }}')
        ->and($this->workflow)->toContain('repository: INIT-ASL/VMS-web-react')
        ->and($this->workflow)->not->toContain('id-token: write');
});

it('checks the ZIP hash and runs that release\'s own deploy.ps1 from outside the app folder', function () {
    expect($this->workflow)->toContain('Get-FileHash -LiteralPath $zip -Algorithm SHA256')
        ->and($this->workflow)->toContain('if ($actual -ne $manifest.sha256)')
        ->and($this->workflow)->toContain("Join-Path \$unpack 'tools\\windows\\deploy.ps1'")
        ->and($this->workflow)->toContain('$env:RUNNER_TEMP');
});
