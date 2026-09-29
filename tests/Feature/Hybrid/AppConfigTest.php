<?php

use Illuminate\Support\Facades\File;

/*
 * Decision PE-7: one SPA build serves every yard; each yard's server says whose name goes on the pass
 * and the reports through GET /api/app-config, from its own .env (VMS_COMPANY_*). The ASL server must
 * print ASL's name, not the CIS pilot's that used to be hard-coded.
 */

beforeEach(function () {
    config([
        'vms.branding.company_name' => 'PT. ASL Shipyard Indonesia',
        'vms.branding.company_address' => 'Tanjung Uncang, Batam',
        'vms.branding.logo' => null,
        'vms.sync.yard_code' => 'ASL',
    ]);
});

it('tells the SPA whose name and address to print, without a login', function () {
    $this->getJson('/api/app-config')
        ->assertOk()
        ->assertExactJson([
            'company_name' => 'PT. ASL Shipyard Indonesia',
            'company_address' => 'Tanjung Uncang, Batam',
            'logo_url' => null,
            'yard_code' => 'ASL',
        ]);
});

it('offers a logo only when the file exists in public/branding', function () {
    $dir = public_path('branding');
    $created = ! File::isDirectory($dir);
    File::ensureDirectoryExists($dir);
    File::put($dir.DIRECTORY_SEPARATOR.'pest-logo.png', 'not really a png');

    try {
        config(['vms.branding.logo' => 'pest-logo.png']);
        $this->getJson('/api/app-config')->assertJsonPath('logo_url', '/branding/pest-logo.png');

        config(['vms.branding.logo' => 'missing.png']);
        $this->getJson('/api/app-config')->assertJsonPath('logo_url', null);

        config(['vms.branding.logo' => '../.env']);
        $this->getJson('/api/app-config')->assertJsonPath('logo_url', null);
    } finally {
        File::delete($dir.DIRECTORY_SEPARATOR.'pest-logo.png');
        if ($created) {
            File::deleteDirectory($dir);
        }
    }
});

it('prints the configured company on the visitor report PDF', function () {
    $html = view('pdf.visitor-report', ['visits' => collect(), 'startDate' => null, 'endDate' => null, 'printedBy' => 'Tester'])->render();

    expect($html)->toContain('PT. ASL SHIPYARD INDONESIA')
        ->and($html)->not->toContain('CEMARA');
});
