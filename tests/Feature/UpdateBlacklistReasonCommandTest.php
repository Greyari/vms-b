<?php

namespace Tests\Feature;

use App\Models\Blacklist;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateBlacklistReasonCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dry_runs_without_modifying_database(): void
    {
        $visitor = Visitor::factory()->create([
            'is_blacklisted' => true,
            'blacklist_reason' => 'Blacklisted by ASL Management for safety breach',
        ]);

        $blacklist = Blacklist::create([
            'visitor_id' => $visitor->id,
            'name' => $visitor->name,
            'id_number' => $visitor->id_number,
            'offences' => 'Blacklisted by ASL Management',
            'remark' => 'Prior incident - Blacklisted by ASL Management',
            'blacklisted_at' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->artisan('vms:update-blacklist-reason --dry-run')
            ->expectsOutputToContain('MODE DRY-RUN')
            ->expectsOutputToContain('Simulasi selesai')
            ->assertExitCode(0);

        // Verify data was NOT changed
        $this->assertEquals('Blacklisted by ASL Management for safety breach', $visitor->fresh()->blacklist_reason);
        $this->assertEquals('Blacklisted by ASL Management', $blacklist->fresh()->offences);
        $this->assertEquals('Prior incident - Blacklisted by ASL Management', $blacklist->fresh()->remark);
    }

    public function test_it_updates_blacklist_reasons_in_both_tables(): void
    {
        $visitor = Visitor::factory()->create([
            'is_blacklisted' => true,
            'blacklist_reason' => 'Blacklisted by ASL Management for safety breach',
        ]);

        $blacklist = Blacklist::create([
            'visitor_id' => $visitor->id,
            'name' => $visitor->name,
            'id_number' => $visitor->id_number,
            'offences' => 'Blacklisted by ASL Management',
            'remark' => 'Prior incident - Blacklisted by ASL Management',
            'blacklisted_at' => now()->toDateString(),
            'is_active' => true,
        ]);

        $this->artisan('vms:update-blacklist-reason')
            ->expectsOutputToContain('Sukses! Total')
            ->assertExitCode(0);

        // Verify data was replaced
        $this->assertEquals('blok by asl management for safety breach', $visitor->fresh()->blacklist_reason);
        $this->assertEquals('blok by asl management', $blacklist->fresh()->offences);
        $this->assertEquals('Prior incident - blok by asl management', $blacklist->fresh()->remark);
    }

    public function test_it_supports_custom_from_and_to_parameters(): void
    {
        $visitor = Visitor::factory()->create([
            'is_blacklisted' => true,
            'blacklist_reason' => 'Custom reason to be changed',
        ]);

        $this->artisan('vms:update-blacklist-reason', [
            '--from' => 'Custom reason',
            '--to' => 'Peraturan Baru',
        ])
            ->assertExitCode(0);

        $this->assertEquals('Peraturan Baru to be changed', $visitor->fresh()->blacklist_reason);
    }
}
