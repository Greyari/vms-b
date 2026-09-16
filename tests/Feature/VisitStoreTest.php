<?php

use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

beforeEach(function () {
    actingAsAdmin();
});

function validVisitPayload(array $overrides = []): array
{
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    return array_merge([
        'id_type' => 'ktp',
        'id_number' => '3210000000000001',
        'name' => 'John Visitor',
        'company' => 'PT Example',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Business meeting',
        'vehicle_no' => 'B 1234 XYZ',
    ], $overrides);
}

it('records a valid visit with ticket 00001 on an empty database', function () {
    $response = $this->postJson('/api/visits', validVisitPayload());

    $response->assertStatus(201);
    expect($response->json('visit.ticket_number'))->toMatch('/^\d{2}\/\d{2}\/00001$/');
});

it('increments the ticket number to 00002 for a second registration', function () {
    $this->postJson('/api/visits', validVisitPayload(['id_number' => '3210000000000001']))
        ->assertStatus(201);

    $second = $this->postJson('/api/visits', validVisitPayload(['id_number' => '3210000000000002']));

    $second->assertStatus(201);
    expect($second->json('visit.ticket_number'))->toMatch('/^\d{2}\/\d{2}\/00002$/');
});

it('flips the assigned visitor and vehicle cards to in_use', function () {
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();
    $vehicle = VehicleCard::factory()->create();

    $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000009',
        'name' => 'Jane',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'vehicle_card_id' => $vehicle->id,
        'purpose' => 'Delivery',
    ])->assertStatus(201);

    expect($card->fresh()->status)->toBe('in_use');
    expect($vehicle->fresh()->status)->toBe('in_use');
});

it('writes pivot rows with non-null timestamps (B12 withTimestamps)', function () {
    $response = $this->postJson('/api/visits', validVisitPayload());
    $visitId = $response->json('visit.id');

    $pivot = DB::table('visit_visitor_card')->where('visit_id', $visitId)->first();

    expect($pivot)->not->toBeNull();
    expect($pivot->created_at)->not->toBeNull();
    expect($pivot->updated_at)->not->toBeNull();
});

it('rejects a locally blacklisted visitor with 422 + reason and leaks nothing', function () {
    $blacklisted = Visitor::factory()->blacklisted('Known troublemaker')->create([
        'id_type' => 'ktp',
        'id_number' => '3210000000000123',
    ]);
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    $response = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000123',
        'name' => 'Known troublemaker',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ]);

    $response->assertStatus(422)->assertJsonStructure(['message', 'reason']);
    expect($response->json('reason'))->toBe('Known troublemaker');

    // Rollback: no visit created, card untouched.
    expect(Visit::count())->toBe(0);
    expect($card->fresh()->status)->toBe('available');
});

it('rejects a visitor who already has an active visit with 422', function () {
    $payload = validVisitPayload(['id_number' => '3210000000000055']);
    $this->postJson('/api/visits', $payload)->assertStatus(201);

    // Same identity, a fresh available card — should be blocked as active.
    $freshCard = VisitorCard::factory()->create();
    $response = $this->postJson('/api/visits', array_merge($payload, [
        'card_ids' => [$freshCard->id],
    ]));

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('active visit');
});

it('rejects a card that is already in_use with 422 and leaks no visit', function () {
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->inUse()->create();

    $response = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000777',
        'name' => 'Late Comer',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ]);

    $response->assertStatus(422);
    expect($response->json('message'))->toContain('in use');
    expect(Visit::count())->toBe(0);
    // Rollback also means the firstOrCreate'd visitor is not persisted.
    expect(Visitor::where('id_number', '3210000000000777')->exists())->toBeFalse();
});

it('accepts a request that omits the vehicle_no key entirely (B7)', function () {
    $payload = validVisitPayload();
    unset($payload['vehicle_no']);

    $this->postJson('/api/visits', $payload)->assertStatus(201);
});

it('rejects a non-uuid staff_id with 422, not 500 (H3)', function () {
    $this->postJson('/api/visits', validVisitPayload(['staff_id' => 'abc']))
        ->assertStatus(422);
});

it('rejects a well-formed but non-existent staff_id with 422', function () {
    $this->postJson('/api/visits', validVisitPayload(['staff_id' => (string) Str::uuid7()]))
        ->assertStatus(422);
});

it('retries a check-in that loses a visitor_no race once and creates exactly one visitor', function () {
    $triggered = false;
    raceVisitorNoOnCreating(function (Visitor $visitor) use (&$triggered): void {
        if ($triggered) {
            return;
        }
        $triggered = true;
        DB::table('visitors')->insert([
            'id' => (string) Str::uuid7(),
            'id_type' => 'ktp',
            'id_number' => 'RACEDECOY'.$visitor->visitor_no,
            'name' => 'Race Decoy',
            'visitor_no' => $visitor->visitor_no,
            'is_blacklisted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $response = $this->postJson('/api/visits', validVisitPayload(['id_number' => '3210000000000501']));

        $response->assertStatus(201);
        expect(Visitor::where('id_number', '3210000000000501')->count())->toBe(1);
        expect(Visit::count())->toBe(1);
        // Not asserted: that the recomputed visitor_no differs from the raced
        // one. It provably can't, here — the decoy is written inside the same
        // per-attempt DB::transaction() as the failed insert (see the
        // Visitor::nextVisitorNo() docblock), so losing the race rolls the
        // decoy back too, and the retry's nextVisitorNo() sees an empty table
        // again and recomputes the same floor value. VisitorImportTest.php's
        // three race tests hit the identical mechanics and assert the same
        // way: exactly the right rows exist, and the decoy never persisted.
        expect(Visitor::where('name', 'Race Decoy')->exists())->toBeFalse();
    } finally {
        Visitor::flushEventListeners();
        Visitor::clearBootedModels();
    }
});

it('treats the same id_number with a different id_type as a second visitor (M2)', function () {
    Visitor::factory()->create([
        'id_type' => 'ktp',
        'id_number' => '3210000000000042',
        'name' => 'KTP Person',
    ]);

    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    $this->postJson('/api/visits', [
        'id_type' => 'passport',
        'id_number' => '3210000000000042',
        'name' => 'Passport Person',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ])->assertStatus(201);

    expect(Visitor::where('id_number', '3210000000000042')->count())->toBe(2);
});

it('does not retry a check-in that fails on an unrelated unique index', function () {
    Exceptions::fake();
    $payload = validVisitPayload(['id_number' => '3210000000000601']);
    $existingCardNo = VisitorCard::findOrFail($payload['card_ids'][0])->card_no;
    $attempts = 0;

    // Boot Visit first so this listener runs after the model's own ones.
    new Visit;
    Visit::creating(function () use (&$attempts, $existingCardNo): void {
        $attempts++;
        DB::table('visitor_cards')->insert([
            'id' => (string) Str::uuid7(),
            'card_no' => $existingCardNo,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $this->postJson('/api/visits', $payload)
            ->assertStatus(500)
            ->assertJsonPath('error', 'The visit conflicts with an existing record.');

        expect($attempts)->toBe(1);
        expect(Visit::count())->toBe(0);
        Exceptions::assertReported(UniqueConstraintViolationException::class);
    } finally {
        Visit::flushEventListeners();
        Visit::clearBootedModels();
    }
});

it('hides an unexpected check-in error from the client and reports it', function () {
    Exceptions::fake();
    $payload = validVisitPayload(['id_number' => '3210000000000602']);

    // Boot Visit first so this listener runs after the model's own ones.
    new Visit;
    Visit::creating(function (): void {
        throw new RuntimeException('PRIVATE-SQL-DETAIL');
    });

    try {
        $this->postJson('/api/visits', $payload)
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Failed to record visit',
                'error' => 'An unexpected error stopped the visit from being recorded.',
            ]);

        expect(Visit::count())->toBe(0);
        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'PRIVATE-SQL-DETAIL');
    } finally {
        Visit::flushEventListeners();
        Visit::clearBootedModels();
    }
});
