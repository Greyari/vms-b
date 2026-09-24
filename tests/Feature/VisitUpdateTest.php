<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\User;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Support\Str;

beforeEach(function () {
    actingAsAdmin();
});

function createOpenVisit(array $attributes = []): Visit
{
    $visitor = Visitor::factory()->create();
    $staff = Staff::factory()->create();
    $location = Location::factory()->create();
    $card = VisitorCard::factory()->inUse()->create();

    $visit = Visit::factory()->create(array_merge([
        'visitor_id' => $visitor->id,
        'staff_id' => $staff->id,
        'location_id' => $location->id,
        'location_name' => $location->name,
        'host_name' => $staff->name,
        'visitor_company' => $visitor->company,
        'purpose' => 'Initial Meeting',
        'vehicle_no' => 'B 1111 AAA',
        'checked_out_at' => null,
    ], $attributes));

    $visit->visitorCards()->sync([$card->id]);

    return $visit;
}

it('returns 404 when updating non-existent visit', function () {
    $nonExistentId = (string) Str::uuid7();

    $this->putJson("/api/visits/{$nonExistentId}", [
        'purpose' => 'New Purpose',
    ])->assertStatus(404);
});

it('rejects updating an already checked-out visit with 422', function () {
    $visit = createOpenVisit([
        'checked_out_at' => now(),
        'time_out' => now()->toTimeString(),
    ]);

    $this->putJson("/api/visits/{$visit->id}", [
        'purpose' => 'New Purpose',
    ])->assertStatus(422)
        ->assertJson(['message' => 'Cannot update a visit that has already been checked out']);
});

it('updates location and snapshots new location_name', function () {
    $visit = createOpenVisit();
    $newLocation = Location::factory()->create(['name' => 'NEW FABRICATION YARD']);

    $response = $this->putJson("/api/visits/{$visit->id}", [
        'location_id' => $newLocation->id,
    ])->assertStatus(200);

    expect($response->json('visit.location_id'))->toBe($newLocation->id);
    expect($response->json('visit.location_name'))->toBe('NEW FABRICATION YARD');

    $visit->refresh();
    expect($visit->location_id)->toBe($newLocation->id);
    expect($visit->location_name)->toBe('NEW FABRICATION YARD');
});

it('updates staff and snapshots staff name into host_name', function () {
    $visit = createOpenVisit();
    $newStaff = Staff::factory()->create(['name' => 'Bambang Sudarsono']);

    $response = $this->putJson("/api/visits/{$visit->id}", [
        'staff_id' => $newStaff->id,
    ])->assertStatus(200);

    expect($response->json('visit.staff.id'))->toBe($newStaff->id);
    expect($response->json('visit.host_name'))->toBe('Bambang Sudarsono');

    $visit->refresh();
    expect($visit->staff_id)->toBe($newStaff->id);
    expect($visit->host_name)->toBe('Bambang Sudarsono');
});

it('updates host_name and clears staff_id when host_name is provided with null staff_id', function () {
    $visit = createOpenVisit();

    $response = $this->putJson("/api/visits/{$visit->id}", [
        'staff_id' => null,
        'host_name' => 'Pak Joko Guest Speaker',
    ])->assertStatus(200);

    expect($response->json('visit.staff'))->toBeNull();
    expect($response->json('visit.host_name'))->toBe('Pak Joko Guest Speaker');

    $visit->refresh();
    expect($visit->staff_id)->toBeNull();
    expect($visit->host_name)->toBe('Pak Joko Guest Speaker');
});

it('updates purpose and vehicle_no', function () {
    $visit = createOpenVisit();

    $response = $this->putJson("/api/visits/{$visit->id}", [
        'purpose' => 'Urgent Equipment Inspection',
        'vehicle_no' => 'BP 9999 XYZ',
    ])->assertStatus(200);

    expect($response->json('visit.purpose'))->toBe('Urgent Equipment Inspection');
    expect($response->json('visit.vehicle_no'))->toBe('BP 9999 XYZ');

    $visit->refresh();
    expect($visit->purpose)->toBe('Urgent Equipment Inspection');
    expect($visit->vehicle_no)->toBe('BP 9999 XYZ');
});

it('updates visitor_company and syncs master visitor company', function () {
    $visit = createOpenVisit();
    $visitor = $visit->visitor;

    $response = $this->putJson("/api/visits/{$visit->id}", [
        'visitor_company' => 'PT Mitra Samudera Raya',
    ])->assertStatus(200);

    expect($response->json('visit.visitor_company'))->toBe('PT Mitra Samudera Raya');

    $visit->refresh();
    expect($visit->visitor_company)->toBe('PT Mitra Samudera Raya');

    $visitor->refresh();
    expect($visitor->company)->toBe('PT Mitra Samudera Raya');
});

it('swaps visitor cards, releasing old cards to available and setting new cards to in_use', function () {
    $oldCard = VisitorCard::factory()->inUse()->create(['card_no' => 'CARD-OLD-01']);
    $visit = createOpenVisit();
    $visit->visitorCards()->sync([$oldCard->id]);

    $newCard = VisitorCard::factory()->create(['card_no' => 'CARD-NEW-02']);

    $response = $this->putJson("/api/visits/{$visit->id}", [
        'card_ids' => [$newCard->id],
    ])->assertStatus(200);

    expect($oldCard->fresh()->status)->toBe('available');
    expect($newCard->fresh()->status)->toBe('in_use');

    $visit->refresh();
    expect($visit->visitorCards->pluck('id')->all())->toBe([$newCard->id]);
});

it('rejects swapping to a visitor card that is already in_use with 422 without modifying existing cards', function () {
    $existingCard = VisitorCard::factory()->inUse()->create(['card_no' => 'CARD-MY-01']);
    $visit = createOpenVisit();
    $visit->visitorCards()->sync([$existingCard->id]);

    $otherInUseCard = VisitorCard::factory()->inUse()->create(['card_no' => 'CARD-BUSY-02']);

    $response = $this->putJson("/api/visits/{$visit->id}", [
        'card_ids' => [$otherInUseCard->id],
    ])->assertStatus(422);

    expect($response->json('message'))->toContain('in use');

    // Existing card still attached and in_use
    expect($existingCard->fresh()->status)->toBe('in_use');
    expect($otherInUseCard->fresh()->status)->toBe('in_use');
    expect($visit->fresh()->visitorCards->pluck('id')->all())->toBe([$existingCard->id]);
});

it('swaps vehicle card, releasing old vehicle card and claiming new one', function () {
    $oldVehicleCard = VehicleCard::factory()->inUse()->create();
    $visit = createOpenVisit(['vehicle_card_id' => $oldVehicleCard->id]);

    $newVehicleCard = VehicleCard::factory()->create();

    $response = $this->putJson("/api/visits/{$visit->id}", [
        'vehicle_card_id' => $newVehicleCard->id,
    ])->assertStatus(200);

    expect($oldVehicleCard->fresh()->status)->toBe('available');
    expect($newVehicleCard->fresh()->status)->toBe('in_use');

    $visit->refresh();
    expect($visit->vehicle_card_id)->toBe($newVehicleCard->id);
});

it('requires update_visits permission to call the endpoint', function () {
    $visit = createOpenVisit();

    // Create user without update_visits permission
    $userWithoutPermission = User::factory()->create();
    $userWithoutPermission->givePermissionTo('view_visits');
    actingAsUser($userWithoutPermission);

    $this->putJson("/api/visits/{$visit->id}", [
        'purpose' => 'Hacked Purpose',
    ])->assertStatus(403);
});
