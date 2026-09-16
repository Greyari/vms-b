<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    actingAsAdmin();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('records the full checkout timestamp for a checkout on a later day', function () {
    Carbon::setTestNow('2026-09-12 22:15:00');
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    $visitId = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000301',
        'name' => 'Night Crew',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'RETURN TO VESSEL',
    ])->assertStatus(201)->json('visit.id');

    Carbon::setTestNow('2026-09-14 06:40:00');
    $this->postJson("/api/visits/{$visitId}/checkout")->assertOk();

    $visit = Visit::find($visitId);
    expect($visit->checked_out_at->toDateTimeString())->toBe('2026-09-14 06:40:00');
    expect($visit->time_out)->toBe('06:40:00');
});

it('lists a multi-day open visit as active and drops it once checked out', function () {
    $open = Visit::factory()->create(['date' => now()->subDays(3)->toDateString()]);
    $closed = Visit::factory()->checkedOut()->create();

    $ids = collect($this->getJson('/api/visits/active')->assertOk()->json())->pluck('id');

    expect($ids)->toContain($open->id);
    expect($ids)->not->toContain($closed->id);
});

it('counts active visits on the dashboard by checked_out_at', function () {
    Visit::factory()->count(2)->create();
    Visit::factory()->checkedOut()->create();

    expect($this->getJson('/api/dashboard')->json('stats.active_visits'))->toBe(2);
});

it('keeps visit history when the host staff record is deleted', function () {
    $staff = Staff::factory()->create();
    $visit = Visit::factory()->create(['staff_id' => $staff->id]);

    $staff->forceDelete();

    $fresh = Visit::find($visit->id);
    expect($fresh)->not->toBeNull();
    expect($fresh->staff_id)->toBeNull();
});

it('links a visit to a location and keeps the snapshots', function () {
    $location = Location::factory()->create(['name' => 'TEST BARGE 101']);
    $visit = Visit::factory()->create([
        'location_id' => $location->id,
        'location_name' => 'TEST BARGE 101 (EX.SAMPLE 101)',
        'host_name' => 'Pak Contoh',
        'visitor_company' => 'PT Contoh',
        'legacy_registration_no' => '26/09/0012345/03',
    ]);

    expect($visit->fresh()->location->is($location))->toBeTrue();
    expect($location->visits()->pluck('id')->all())->toContain($visit->id);
});

it('backfills checked_out_at from date and time_out, rolling past midnight', function () {
    $visitor = Visitor::factory()->create();
    $row = fn (string $purpose, string $timeIn, string $timeOut) => [
        'id' => (string) Str::uuid7(),
        'visitor_id' => $visitor->id,
        'purpose' => $purpose,
        'date' => '2026-09-01',
        'time_in' => $timeIn,
        'time_out' => $timeOut,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('visits')->insert([
        $row('Same day', '08:00:00', '17:30:00'),
        $row('Overnight', '22:00:00', '06:15:00'),
    ]);

    (require database_path('migrations/2026_09_13_100800_backfill_visits_checked_out_at.php'))->up();

    $checkedOut = fn (string $purpose) => Carbon::parse(DB::table('visits')->where('purpose', $purpose)->value('checked_out_at'))->toDateTimeString();

    expect($checkedOut('Same day'))->toBe('2026-09-01 17:30:00');
    expect($checkedOut('Overnight'))->toBe('2026-09-02 06:15:00');
});
