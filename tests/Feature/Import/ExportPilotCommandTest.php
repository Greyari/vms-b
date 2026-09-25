<?php

namespace Tests\Feature\Import;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExportPilotCommandTest extends TestCase
{
    private string $exportPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportPath = storage_path('framework/testing/pilot_export_'.uniqid());

        // Setup temporary sqlite connection for pilot
        Config::set('database.connections.pilot_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $schema = Schema::connection('pilot_test');

        $schema->create('visitors', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('ktp_number')->nullable();
            $table->string('company')->nullable();
            $table->timestamps();
        });

        $schema->create('staff', function ($table) {
            $table->id();
            $table->string('staff_id');
            $table->string('name');
            $table->string('department');
            $table->timestamps();
        });

        $schema->create('visitor_cards', function ($table) {
            $table->id();
            $table->string('card_number');
            $table->string('status')->default('available');
            $table->timestamps();
        });

        $schema->create('vehicle_cards', function ($table) {
            $table->id();
            $table->string('card_number');
            $table->timestamps();
        });

        $schema->create('visits', function ($table) {
            $table->id();
            $table->string('ticket_number');
            $table->unsignedBigInteger('visitor_id');
            $table->unsignedBigInteger('staff_id');
            $table->date('date');
            $table->time('time_in');
            $table->time('time_out')->nullable();
            $table->string('status')->default('checked_in');
            $table->timestamps();
        });

        $schema->create('roles', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        $schema->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        $schema->create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        $schema->create('visit_visitor_card', function ($table) {
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('visitor_card_id');
            $table->timestamps();
        });

        // Seed sample pilot data
        $pilotDb = DB::connection('pilot_test');

        $pilotDb->table('visitors')->insert([
            ['id' => 1, 'name' => 'Budi Santoso', 'ktp_number' => '1234567890123456', 'company' => 'PT Maju', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Siti Rahma', 'ktp_number' => '2345678901234567', 'company' => 'PT Jaya', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pilotDb->table('staff')->insert([
            ['id' => 1, 'staff_id' => 'ST-001', 'name' => 'Ahmad HR', 'department' => 'HR', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pilotDb->table('visitor_cards')->insert([
            ['id' => 1, 'card_number' => 'VC-01', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pilotDb->table('vehicle_cards')->insert([
            ['id' => 1, 'card_number' => 'VH-01', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pilotDb->table('visits')->insert([
            ['id' => 1, 'ticket_number' => 'T-20260501-001', 'visitor_id' => 1, 'staff_id' => 1, 'date' => '2026-05-01', 'time_in' => '08:00:00', 'time_out' => '17:00:00', 'status' => 'checked_out', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pilotDb->table('roles')->insert([
            ['id' => 1, 'name' => 'administrator', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'security', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pilotDb->table('users')->insert([
            ['id' => 1, 'name' => 'Admin Pilot', 'email' => 'admin@pilot.test', 'password' => 'secret_hash', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $pilotDb->table('model_has_roles')->insert([
            ['role_id' => 1, 'model_type' => 'App\\Models\\User', 'model_id' => 1],
            ['role_id' => 2, 'model_type' => 'App\\Models\\User', 'model_id' => 1],
        ]);

        $pilotDb->table('visit_visitor_card')->insert([
            ['id' => 1, 'visit_id' => 1, 'visitor_card_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->exportPath)) {
            File::deleteDirectory($this->exportPath);
        }

        parent::tearDown();
    }

    public function test_fails_when_path_is_missing(): void
    {
        $this->artisan('vms:export-pilot')
            ->expectsOutputToContain('The --path option is required.')
            ->assertExitCode(1);
    }

    public function test_exports_pilot_data_to_csv_and_manifest(): void
    {
        $this->artisan('vms:export-pilot', [
            '--connection' => 'pilot_test',
            '--path' => $this->exportPath,
        ])->assertExitCode(0);

        $this->assertFileExists($this->exportPath.'/manifest.json');
        $this->assertFileExists($this->exportPath.'/visitors.csv');
        $this->assertFileExists($this->exportPath.'/staffs.csv');
        $this->assertFileExists($this->exportPath.'/cards.csv');
        $this->assertFileExists($this->exportPath.'/vehicle_cards.csv');
        $this->assertFileExists($this->exportPath.'/visits.csv');
        $this->assertFileExists($this->exportPath.'/users.csv');
        $this->assertFileExists($this->exportPath.'/visit_visitor_card.csv');

        $manifest = json_decode(file_get_contents($this->exportPath.'/manifest.json'), true);
        $this->assertSame('pilot', $manifest['source']);
        $this->assertSame(2, $manifest['tables']['visitors']['row_count']);
        $this->assertSame(1, $manifest['tables']['staffs']['row_count']);
        $this->assertSame(1, $manifest['tables']['cards']['row_count']);
        $this->assertSame(1, $manifest['tables']['vehicle_cards']['row_count']);
        $this->assertSame(1, $manifest['tables']['visits']['row_count']);
        $this->assertSame(1, $manifest['tables']['users']['row_count']);

        // Check visitors.csv content
        $visitorCsv = file_get_contents($this->exportPath.'/visitors.csv');
        $this->assertStringContainsString('Budi Santoso', $visitorCsv);
        $this->assertStringContainsString('1234567890123456', $visitorCsv);

        // Check users.csv contains roles
        $usersCsv = file_get_contents($this->exportPath.'/users.csv');
        $this->assertStringContainsString('admin@pilot.test', $usersCsv);
        $this->assertStringContainsString('administrator,security', $usersCsv);
    }
}
