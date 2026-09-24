<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\VisitorCard;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    actingAsAdmin();
});

/** Create an active visit through the API so cards/pivot are set exactly as prod. */
function createActiveVisit(string $idNumber = '3210000000000900'): array
{
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();
    $vehicle = VehicleCard::factory()->create();
    $location = Location::factory()->create();

    $response = test()->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => $idNumber,
        'name' => 'Life Cycle',
        'staff_id' => $staff->id,
        'location_id' => $location->id,
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

it('hides an unexpected delete error from the client and reports it', function () {
    ['id' => $id, 'card' => $card] = createActiveVisit('3210000000000903');
    Exceptions::fake();

    Visit::deleting(function (): void {
        throw new RuntimeException('PRIVATE-SQL-DETAIL');
    });

    try {
        $this->deleteJson("/api/visits/{$id}")
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Failed to delete visit',
                'error' => 'An unexpected error stopped the visit from being deleted.',
            ]);

        expect(Visit::find($id))->not->toBeNull();
        expect($card->fresh()->status)->toBe('in_use');
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'PRIVATE-SQL-DETAIL');
    } finally {
        Visit::flushEventListeners();
        Visit::clearBootedModels();
    }
});
