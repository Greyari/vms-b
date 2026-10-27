<?php

use App\Models\Location;
use App\Models\User;
use App\Models\VesselArrival;
use App\Models\VesselArrivalCrewLog;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->admin->givePermissionTo(['view_vessel_arrivals', 'manage_vessel_arrivals', 'view_visitors']);
});

test('unified search finds vessels and active crew members with masked id', function () {
    $loc1 = Location::factory()->create(['name' => 'BARGE MAJU 01', 'code' => 'IMO111222']);
    $loc2 = Location::factory()->create(['name' => 'TUG SAMUDERA', 'code' => 'IMO333444']);

    $arrival1 = VesselArrival::factory()->create([
        'location_id' => $loc1->id,
        'vessel_name' => 'BARGE MAJU 01',
    ]);
    $arrival2 = VesselArrival::factory()->create([
        'location_id' => $loc2->id,
        'vessel_name' => 'TUG SAMUDERA',
    ]);

    $crew1 = Visitor::factory()->create([
        'visitor_type' => 'crew',
        'name' => 'JOKO TINGKIR',
        'id_number' => '2171031811850004',
        'company' => 'PT MAJU JAYA',
    ]);
    $crew2 = Visitor::factory()->create([
        'visitor_type' => 'crew',
        'name' => 'JOKO ANWAR',
        'id_number' => '12345678',
        'company' => 'PT SAMUDERA',
    ]);

    // Attach crew1 to arrival1 as checked_in
    $arrival1->crew()->attach($crew1->id, [
        'status' => 'checked_in',
        'checked_in_at' => now(),
    ]);

    // Attach crew2 to arrival2 as checked_out
    $arrival2->crew()->attach($crew2->id, [
        'status' => 'checked_out',
        'checked_in_at' => now()->subDays(2),
        'checked_out_at' => now()->subDay(),
    ]);

    // 1. Search by vessel name
    $resVessel = $this->actingAs($this->admin)->getJson('/api/vessel-arrivals/search?q=BARGE');
    $resVessel->assertOk()
        ->assertJsonCount(1, 'vessels')
        ->assertJsonPath('vessels.0.vessel_name', 'BARGE MAJU 01')
        ->assertJsonPath('vessels.0.active_crew_count', 1)
        ->assertJsonPath('vessels.0.completed_crew_count', 0);

    // 2. Search by crew name always includes both checked_in and checked_out crew (active sorted first)
    $resCrew = $this->actingAs($this->admin)->getJson('/api/vessel-arrivals/search?q=JOKO');
    $resCrew->assertOk()
        ->assertJsonCount(2, 'crew')
        ->assertJsonPath('crew.0.name', 'JOKO TINGKIR')
        ->assertJsonPath('crew.0.vessel_name', 'BARGE MAJU 01')
        ->assertJsonPath('crew.0.status', 'checked_in')
        ->assertJsonPath('crew.0.id_number_masked', '2171********0004')
        ->assertJsonPath('crew.1.name', 'JOKO ANWAR')
        ->assertJsonPath('crew.1.status', 'checked_out');
});

test('it prevents crew from having multiple active placements on different vessels', function () {
    $loc1 = Location::factory()->create(['name' => 'KAPAL SATU']);
    $loc2 = Location::factory()->create(['name' => 'KAPAL DUA']);

    $arr1 = VesselArrival::factory()->create(['location_id' => $loc1->id, 'vessel_name' => 'KAPAL SATU']);
    $arr2 = VesselArrival::factory()->create(['location_id' => $loc2->id, 'vessel_name' => 'KAPAL DUA']);

    $crew = Visitor::factory()->create(['visitor_type' => 'crew', 'name' => 'ANDI BUDI']);

    // Check in on Kapal Satu
    $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr1->id}/crew/check-in", [
        'visitor_id' => $crew->id,
    ])->assertOk();

    // Attempt to check in on Kapal Dua without transfer should fail
    $resFail = $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr2->id}/crew/check-in", [
        'visitor_id' => $crew->id,
    ]);

    $resFail->assertStatus(422)
        ->assertJsonValidationErrors(['conflict']);
});

test('it transfers a crew member from old vessel to new vessel in single transaction', function () {
    $loc1 = Location::factory()->create(['name' => 'KAPAL ASAL']);
    $loc2 = Location::factory()->create(['name' => 'KAPAL TUJUAN']);

    $arr1 = VesselArrival::factory()->create(['location_id' => $loc1->id, 'vessel_name' => 'KAPAL ASAL']);
    $arr2 = VesselArrival::factory()->create(['location_id' => $loc2->id, 'vessel_name' => 'KAPAL TUJUAN']);

    $crew = Visitor::factory()->create(['visitor_type' => 'crew', 'name' => 'BUDI SANTOSO']);

    // Check in to arrival 1
    $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr1->id}/crew/check-in", [
        'visitor_id' => $crew->id,
    ])->assertOk();

    // Transfer to arrival 2
    $resTransfer = $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr2->id}/crew/transfer", [
        'visitor_id' => $crew->id,
        'notes' => 'Rotasi tugas',
    ]);
    $resTransfer->assertOk();

    // Verify placement in old arrival is detached from vessel_arrival_crew so no duplicate placements exist
    $oldPivot = DB::table('vessel_arrival_crew')
        ->where('vessel_arrival_id', $arr1->id)
        ->where('visitor_id', $crew->id)
        ->first();
    expect($oldPivot)->toBeNull();

    // Verify transfer audit log exists on old arrival
    $oldLog = VesselArrivalCrewLog::where('vessel_arrival_id', $arr1->id)
        ->where('visitor_id', $crew->id)
        ->latest('logged_at')
        ->first();
    expect($oldLog)->not->toBeNull()
        ->and($oldLog->action)->toBe('check_out');

    // Verify placement in new arrival is checked_in
    $newPivot = DB::table('vessel_arrival_crew')
        ->where('vessel_arrival_id', $arr2->id)
        ->where('visitor_id', $crew->id)
        ->first();
    expect($newPivot->status)->toBe('checked_in');
    expect($newPivot->checked_out_at)->toBeNull();

    // Verify visitor vessel_id is updated to new vessel
    expect($crew->fresh()->vessel_id)->toBe($loc2->id);
});

test('it forbids check in for blacklisted crew with clear error', function () {
    $loc = Location::factory()->create(['name' => 'KAPAL MERDEKA']);
    $arr = VesselArrival::factory()->create(['location_id' => $loc->id, 'vessel_name' => 'KAPAL MERDEKA']);

    $blacklisted = Visitor::factory()->create([
        'visitor_type' => 'crew',
        'name' => 'BURONAN KRU',
        'is_blacklisted' => true,
        'blacklist_reason' => 'Pelanggaran keamanan fatal',
    ]);

    $res = $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr->id}/crew/check-in", [
        'visitor_id' => $blacklisted->id,
    ]);

    $res->assertStatus(422)
        ->assertJsonValidationErrors(['crew']);
});

test('it reactivates a checked out crew member', function () {
    $loc = Location::factory()->create(['name' => 'KAPAL REAKTIF']);
    $arr = VesselArrival::factory()->create(['location_id' => $loc->id, 'vessel_name' => 'KAPAL REAKTIF']);

    $crew = Visitor::factory()->create(['visitor_type' => 'crew', 'name' => 'KRU REAKTIF']);

    $arr->crew()->attach($crew->id, [
        'status' => 'checked_out',
        'checked_in_at' => now()->subDay(),
        'checked_out_at' => now()->subHours(2),
    ]);

    $res = $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr->id}/crew/reactivate", [
        'visitor_id' => $crew->id,
        'notes' => 'Salah klik checkout',
    ]);
    $res->assertOk();

    $pivot = DB::table('vessel_arrival_crew')
        ->where('vessel_arrival_id', $arr->id)
        ->where('visitor_id', $crew->id)
        ->first();
    expect($pivot->status)->toBe('checked_in');
    expect($pivot->checked_out_at)->toBeNull();
});

test('it provides placement detail and log timeline for person panel', function () {
    $loc = Location::factory()->create(['name' => 'KAPAL PANEL']);
    $arr = VesselArrival::factory()->create(['location_id' => $loc->id, 'vessel_name' => 'KAPAL PANEL']);
    $crew = Visitor::factory()->create([
        'visitor_type' => 'crew',
        'name' => 'KRU DETAIL',
        'company' => 'PT SHIPPING',
    ]);

    $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr->id}/crew/check-in", [
        'visitor_id' => $crew->id,
        'notes' => 'Check in awal',
    ])->assertOk();

    $res = $this->actingAs($this->admin)->getJson("/api/vessel-arrivals/crew/{$crew->id}/placement-detail");
    $res->assertOk()
        ->assertJsonPath('visitor.name', 'KRU DETAIL')
        ->assertJsonPath('current_placement.vessel_name', 'KAPAL PANEL')
        ->assertJsonPath('current_placement.status', 'checked_in')
        ->assertJsonCount(1, 'logs');
});

test('it blocks check-in, reactivate, and transfer if vessel has already departed', function () {
    $loc = Location::factory()->create(['name' => 'KAPAL BERANGKAT']);
    $arr = VesselArrival::factory()->create([
        'location_id' => $loc->id,
        'vessel_name' => 'KAPAL BERANGKAT',
        'sign_out_date' => now()->toDateString(),
    ]);

    $crew = Visitor::factory()->create(['visitor_type' => 'crew', 'name' => 'KRU TERLALU TELAT']);

    // Check-in on departed vessel
    $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr->id}/crew/check-in", [
        'visitor_id' => $crew->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['vessel']);

    // Reactivate on departed vessel
    $arr->crew()->attach($crew->id, [
        'status' => 'checked_out',
        'checked_in_at' => now()->subDays(2),
        'checked_out_at' => now()->subDay(),
    ]);

    $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr->id}/crew/reactivate", [
        'visitor_id' => $crew->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['vessel']);

    // Transfer to departed vessel ($arr has sign_out_date)
    $activeLoc = Location::factory()->create(['name' => 'KAPAL AKTIF']);
    $activeArr = VesselArrival::factory()->create(['location_id' => $activeLoc->id, 'vessel_name' => 'KAPAL AKTIF']);
    $otherCrew = Visitor::factory()->create(['visitor_type' => 'crew', 'name' => 'KRU AKTIF']);
    $activeArr->crew()->attach($otherCrew->id, ['status' => 'checked_in', 'checked_in_at' => now()]);

    $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr->id}/crew/transfer", [
        'visitor_id' => $otherCrew->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['target_arrival']);
});

test('it returns current_placement and crew_logs in visitor show for crew visitor', function () {
    $loc = Location::factory()->create(['name' => 'KAPAL SHOW']);
    $arr = VesselArrival::factory()->create(['location_id' => $loc->id, 'vessel_name' => 'KAPAL SHOW']);
    $crew = Visitor::factory()->create([
        'visitor_type' => 'crew',
        'name' => 'KRU SHOW',
    ]);

    $this->actingAs($this->admin)->postJson("/api/vessel-arrivals/{$arr->id}/crew/check-in", [
        'visitor_id' => $crew->id,
        'notes' => 'Penugasan masuk',
    ])->assertOk();

    $res = $this->actingAs($this->admin)->getJson("/api/visitors/{$crew->id}");
    $res->assertOk()
        ->assertJsonPath('visitor.name', 'KRU SHOW')
        ->assertJsonPath('visitor.current_placement.vessel_name', 'KAPAL SHOW')
        ->assertJsonPath('visitor.current_placement.status', 'checked_in')
        ->assertJsonCount(1, 'visitor.crew_logs')
        ->assertJsonPath('visitor.crew_logs.0.action', 'check_in')
        ->assertJsonPath('visitor.crew_logs.0.vessel_name', 'KAPAL SHOW');
});
