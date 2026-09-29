<?php

use Illuminate\Support\Facades\File;

/*
 * Decision PE-8: VMS-old is ASL's gate history, the IIS pilot is CIS's. Each yard's server imports only
 * its own source, so one company's visitor records never land on the other company's gate.
 */

beforeEach(function () {
    $this->importDir = storage_path('framework/testing/vms_yard_guard_'.uniqid());
    File::makeDirectory($this->importDir, 0755, true);
    File::put($this->importDir.'/Nationality.csv', "NationalityCode,Description,Note\nIDN,INDONESIA,\n");
});

afterEach(function () {
    File::deleteDirectory($this->importDir);
});

function writeManifest(string $dir, string $source): void
{
    File::put($dir.'/manifest.json', json_encode(['source' => $source, 'tables' => []]));
}

it('refuses VMS-old data on the CIS server', function () {
    config(['vms.sync.yard_code' => 'CIS']);
    writeManifest($this->importDir, 'vms-old');

    $this->artisan('vms:import', ['--source' => 'vms-old', '--path' => $this->importDir])
        ->expectsOutputToContain("[vms-old] holds ASL's data, but this server is CIS")
        ->assertExitCode(1);

    $this->assertDatabaseMissing('nationalities', ['name' => 'INDONESIA']);
});

it('refuses pilot data on the ASL server', function () {
    config(['vms.sync.yard_code' => 'ASL']);
    writeManifest($this->importDir, 'pilot');

    $this->artisan('vms:import', ['--source' => 'pilot', '--path' => $this->importDir])
        ->expectsOutputToContain("[pilot] holds CIS's data, but this server is ASL")
        ->assertExitCode(1);
});

it("imports VMS-old on ASL's own server", function () {
    config(['vms.sync.yard_code' => 'ASL']);
    writeManifest($this->importDir, 'vms-old');

    $this->artisan('vms:import', ['--source' => 'vms-old', '--path' => $this->importDir])->assertExitCode(0);

    $this->assertDatabaseHas('nationalities', ['name' => 'INDONESIA']);
});

it('warns but imports when the server has no yard code (developer laptop)', function () {
    config(['vms.sync.yard_code' => '']);
    writeManifest($this->importDir, 'vms-old');

    $this->artisan('vms:import', ['--source' => 'vms-old', '--path' => $this->importDir])
        ->expectsOutputToContain('VMS_YARD_CODE is not set')
        ->assertExitCode(0);
});

it('refuses a folder exported from the other source', function () {
    config(['vms.sync.yard_code' => '']);
    writeManifest($this->importDir, 'pilot');

    $this->artisan('vms:import', ['--source' => 'vms-old', '--path' => $this->importDir])
        ->expectsOutputToContain('was exported from [pilot], not [vms-old]')
        ->assertExitCode(1);
});

it('defines the pilot connection that vms:export-pilot reads by default', function () {
    expect(config('database.connections.pilot.driver'))->toBe('mysql')
        ->and(config('database.connections.pilot.database'))->toBe('db_vms');
});
