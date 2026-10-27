<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutHistoricalVisitsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_guard_rejects_today_and_future_dates(): void
    {
        $today = Carbon::today()->toDateString();
        $tomorrow = Carbon::tomorrow()->toDateString();

        $this->artisan("vms:checkout-historical-visits --before={$today}")
            ->assertExitCode(1)
            ->expectsOutputToContain('Safety Error: Cannot checkout visits from today');

        $this->artisan("vms:checkout-historical-visits --before={$tomorrow}")
            ->assertExitCode(1)
            ->expectsOutputToContain('Safety Error: Cannot checkout visits from today');
    }

    public function test_dry_run_simulates_without_modifying_database(): void
    {
        $visitor = Visitor::factory()->create();
        $staff = Staff::factory()->create();
        $location = Location::factory()->create();
        $card = VisitorCard::create(['card_no' => 'V-999', 'status' => 'in_use', 'is_active' => true]);

        $pastDate = Carbon::today()->subDays(5)->toDateString();

        $visit = Visit::factory()->create([
            'visitor_id' => $visitor->id,
            'staff_id' => $staff->id,
            'location_id' => $location->id,
            'date' => $pastDate,
            'time_in' => '08:00:00',
            'time_out' => null,
            'checked_out_at' => null,
        ]);
        $visit->visitorCards()->attach($card->id);

        $this->artisan("vms:checkout-historical-visits --before={$pastDate} --dry-run")
            ->assertExitCode(0)
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('Found 1 open historical visits matching criteria');

        $visit->refresh();
        expect($visit->checked_out_at)->toBeNull();
        expect($card->fresh()->status)->toBe('in_use');
    }

    public function test_live_execution_checks_out_past_visits_and_preserves_today(): void
    {
        $visitor = Visitor::factory()->create();
        $staff = Staff::factory()->create();
        $location = Location::factory()->create();

        $cardOld = VisitorCard::create(['card_no' => 'V-OLD-1', 'status' => 'in_use', 'is_active' => true]);
        $vehCardOld = VehicleCard::create(['card_no' => 'M-OLD-1', 'status' => 'in_use', 'is_active' => true]);

        $cardToday = VisitorCard::create(['card_no' => 'V-TODAY-1', 'status' => 'in_use', 'is_active' => true]);

        $cutoffDate = Carbon::today()->subDays(3)->toDateString();

        // 1. Past visit to be checked out
        $pastVisit = Visit::factory()->create([
            'visitor_id' => $visitor->id,
            'staff_id' => $staff->id,
            'location_id' => $location->id,
            'vehicle_card_id' => $vehCardOld->id,
            'date' => $cutoffDate,
            'time_in' => '08:15:00',
            'time_out' => null,
            'checked_out_at' => null,
        ]);
        $pastVisit->visitorCards()->attach($cardOld->id);

        // 2. Today's visit that MUST NOT be checked out
        $todayVisit = Visit::factory()->create([
            'visitor_id' => $visitor->id,
            'staff_id' => $staff->id,
            'location_id' => $location->id,
            'date' => Carbon::today()->toDateString(),
            'time_in' => '09:00:00',
            'time_out' => null,
            'checked_out_at' => null,
        ]);
        $todayVisit->visitorCards()->attach($cardToday->id);

        $this->artisan("vms:checkout-historical-visits --before={$cutoffDate}")
            ->assertExitCode(0)
            ->expectsOutputToContain("Check-In Date Cutoff: <= {$cutoffDate}")
            ->expectsOutputToContain('Successfully checked out 1 historical visits');

        // Verify past visit was checked out
        $pastVisit->refresh();
        expect($pastVisit->checked_out_at)->not->toBeNull();
        expect($pastVisit->time_out)->toBe('17:00:00');
        expect($cardOld->fresh()->status)->toBe('available');
        expect($vehCardOld->fresh()->status)->toBe('available');

        // Verify today's visit remains open and untouched!
        $todayVisit->refresh();
        expect($todayVisit->checked_out_at)->toBeNull();
        expect($todayVisit->time_out)->toBeNull();
        expect($cardToday->fresh()->status)->toBe('in_use');
    }
}
