<?php

use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\VisitorCard;

beforeEach(function () {
    actingAsAdmin();
});

/** Create an active visit through the API so cards/pivot are set exactly as prod. */
function createActiveVisit(string $idNumber = '3210000000000900'): array
{
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();
    $vehicle = VehicleCard::factory()->create();

    $response = test()->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => $idNumber,
        'name' => 'Life Cycle',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'vehicle_card_id' => $vehicle->id,
        'purpose' => 'Meeting',
    ]);
    $response->assertStatus(201);

    return [
        'id' => $response->json('visit.id'),
        'card' => $card,
        'vehicle' => $vehicle,
    ];
}

it('checkout sets time_out and frees the visitor and vehicle cards', function () {
    ['id' => $id, 'card' => $card, 'vehicle' => $vehicle] = createActiveVisit();

    expect($card->fresh()->status)->toBe('in_use');
    expect($vehicle->fresh()->status)->toBe('in_use');

    $this->postJson("/api/visits/{$id}/checkout")->assertOk();

    expect(Visit::find($id)->time_out)->not->toBeNull();
    expect($card->fresh()->status)->toBe('available');
    expect($vehicle->fresh()->status)->toBe('available');
});

it('rejects a double checkout with 422', function () {
    ['id' => $id] = createActiveVisit('3210000000000901');

    $this->postJson("/api/visits/{$id}/checkout")->assertOk();
    $this->postJson("/api/visits/{$id}/checkout")
        ->assertStatus(422)
        ->assertJson(['message' => 'Visitor already checked out']);
});

it('deleting an active visit frees its cards', function () {
    ['id' => $id, 'card' => $card, 'vehicle' => $vehicle] = createActiveVisit('3210000000000902');

    $this->deleteJson("/api/visits/{$id}")->assertOk();

    expect(Visit::find($id))->toBeNull();
    expect($card->fresh()->status)->toBe('available');
    expect($vehicle->fresh()->status)->toBe('available');
});
