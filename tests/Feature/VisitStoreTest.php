<?php

use App\Enums\LocationType;
use App\Enums\VisitorType;
use App\Models\Location;
use App\Models\Staff;
use App\Models\User;
use App\Models\VehicleCard;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Support\SequenceLock;
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
    $location = Location::factory()->create();

    return array_merge([
        'id_type' => 'ktp',
        'id_number' => '3210000000000001',
        'name' => 'John Visitor',
        'company' => 'PT Example',
        'staff_id' => $staff->id,
        'location_id' => $location->id,
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
    $location = Location::factory()->create();

    $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000009',
        'name' => 'Jane',
        'staff_id' => $staff->id,
        'location_id' => $location->id,
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
    $location = Location::factory()->create();

    $response = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000123',
        'name' => 'Known troublemaker',
        'staff_id' => $staff->id,
        'location_id' => $location->id,
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
    $location = Location::factory()->create();

    $response = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000777',
        'name' => 'Late Comer',
        'staff_id' => $staff->id,
        'location_id' => $location->id,
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
    $location = Location::factory()->create();

    $this->postJson('/api/visits', [
        'id_type' => 'passport',
        'id_number' => '3210000000000042',
        'name' => 'Passport Person',
        'staff_id' => $staff->id,
        'location_id' => $location->id,
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

it('takes the ticket lock before the check-in guards read open visits', function () {
    requirePostgres();

    $payload = validVisitPayload(['id_number' => '3210000000000701']);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
    });

    $this->postJson('/api/visits', $payload)->assertStatus(201);

    $ticketLock = collect($queries)->search(fn (array $query): bool => str_contains($query['sql'], 'pg_advisory_xact_lock')
        && $query['bindings'] === [SequenceLock::key('ticket_number')]);
    $openVisitCheck = collect($queries)->search(fn (array $query): bool => str_contains($query['sql'], '"checked_out_at" is null'));

    expect($ticketLock)->not->toBeFalse();
    expect($openVisitCheck)->not->toBeFalse();
    expect($ticketLock)->toBeLessThan($openVisitCheck);
})->group('pgsql');

it('requires a location_id and validates it exists', function () {
    $this->postJson('/api/visits', validVisitPayload(['location_id' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['location_id']);

    $this->postJson('/api/visits', validVisitPayload(['location_id' => (string) Str::uuid7()]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['location_id']);
});

it('snapshots the location name into visits.location_name', function () {
    $location = Location::factory()->create(['name' => 'DOCK 2 WORKSHOP']);
    $response = $this->postJson('/api/visits', validVisitPayload(['location_id' => $location->id]))
        ->assertStatus(201);

    $visit = Visit::find($response->json('visit.id'));
    expect($visit->location_id)->toBe($location->id);
    expect($visit->location_name)->toBe('DOCK 2 WORKSHOP');
    expect($response->json('visit.location_name'))->toBe('DOCK 2 WORKSHOP');
});

it('allows check-in with host_name instead of staff_id and snapshots host_name', function () {
    $response = $this->postJson('/api/visits', validVisitPayload([
        'staff_id' => null,
        'host_name' => 'Pak Budi Ops',
    ]))->assertStatus(201);

    $visit = Visit::find($response->json('visit.id'));
    expect($visit->staff_id)->toBeNull();
    expect($visit->host_name)->toBe('Pak Budi Ops');
    expect($response->json('visit.host_name'))->toBe('Pak Budi Ops');
});

it('snapshots staff name into host_name when staff_id is provided', function () {
    $staff = Staff::factory()->create(['name' => 'Ir. Hartono']);
    $response = $this->postJson('/api/visits', validVisitPayload([
        'staff_id' => $staff->id,
        'host_name' => null,
    ]))->assertStatus(201);

    $visit = Visit::find($response->json('visit.id'));
    expect($visit->staff_id)->toBe($staff->id);
    expect($visit->host_name)->toBe('Ir. Hartono');
    expect($response->json('visit.host_name'))->toBe('Ir. Hartono');
});

it('rejects check-in when neither staff_id nor host_name is provided', function () {
    $this->postJson('/api/visits', validVisitPayload([
        'staff_id' => null,
        'host_name' => null,
    ]))->assertStatus(422)
        ->assertJsonValidationErrors(['staff_id', 'host_name']);
});

it('snapshots visitor_company to visit and updates master visitor company when changed', function () {
    $visitor = Visitor::factory()->create([
        'id_type' => 'ktp',
        'id_number' => '3210000000000888',
        'company' => 'Old Enterprise',
    ]);

    $response = $this->postJson('/api/visits', validVisitPayload([
        'id_type' => 'ktp',
        'id_number' => '3210000000000888',
        'company' => 'New Global Logistics',
    ]))->assertStatus(201);

    $visit = Visit::find($response->json('visit.id'));
    expect($visit->visitor_company)->toBe('New Global Logistics');
    expect($response->json('visit.visitor_company'))->toBe('New Global Logistics');

    $visitor->refresh();
    expect($visitor->company)->toBe('New Global Logistics');
});

it('registers a new visitor with full profile attributes during check-in', function () {
    $vessel = Location::factory()->create([
        'type' => LocationType::RepairVessel,
    ]);

    $payload = validVisitPayload([
        'id_type' => 'passport',
        'id_number' => 'A98765432',
        'name' => 'Capt. Jack Maritim',
        'company' => 'Ocean Shipping Ltd',
        'visitor_type' => 'crew',
        'gender' => 'M',
        'birth_date' => '1985-04-12',
        'birth_place' => 'Surabaya',
        'nationality' => 'ID',
        'occupation' => 'Chief Officer',
        'phone' => '08129876543',
        'id_issued_at' => '2020-01-10',
        'id_expires_at' => '2030-01-10',
        'id_lifetime' => false,
        'vessel_id' => $vessel->id,
        'certificate' => 'ANT-II',
        'certificate_no' => 'CERT-2026-99',
        'seaman_book_no' => 'SB-2026-88',
        'seaman_book_expires_at' => '2028-12-31',
    ]);

    $response = $this->postJson('/api/visits', $payload)->assertStatus(201);

    $visitor = Visitor::where('id_number', 'A98765432')->first();
    expect($visitor)->not->toBeNull();
    expect($visitor->visitor_no)->not->toBeNull();
    expect($visitor->visitor_type)->toBe(VisitorType::Crew);
    expect($visitor->gender)->toBe('M');
    expect($visitor->birth_date?->toDateString())->toBe('1985-04-12');
    expect($visitor->birth_place)->toBe('Surabaya');
    expect($visitor->nationality)->toBe('ID');
    expect($visitor->occupation)->toBe('Chief Officer');
    expect($visitor->phone)->toBe('08129876543');
    expect($visitor->vessel_id)->toBe($vessel->id);
    expect($visitor->certificate)->toBe('ANT-II');
    expect($visitor->certificate_no)->toBe('CERT-2026-99');
    expect($visitor->seaman_book_no)->toBe('SB-2026-88');
    expect($visitor->seaman_book_expires_at?->toDateString())->toBe('2028-12-31');

    $visit = Visit::find($response->json('visit.id'));
    expect($visit->visitor_company)->toBe('Ocean Shipping Ltd');
});

it('falls back to existing visitor company when company is omitted from check-in payload', function () {
    $visitor = Visitor::factory()->create([
        'id_type' => 'ktp',
        'id_number' => '3210000000000777',
        'company' => 'Static Industries',
    ]);

    $response = $this->postJson('/api/visits', validVisitPayload([
        'id_type' => 'ktp',
        'id_number' => '3210000000000777',
        'company' => null,
    ]))->assertStatus(201);

    $visit = Visit::find($response->json('visit.id'));
    expect($visit->visitor_company)->toBe('Static Industries');
    expect($response->json('visit.visitor_company'))->toBe('Static Industries');

    $visitor->refresh();
    expect($visitor->company)->toBe('Static Industries');
});

it('rejects an active visitor with open_visit_id when close_open_visits is not true', function () {
    $payload = validVisitPayload(['id_number' => '3210000000000999']);
    $firstResponse = $this->postJson('/api/visits', $payload)->assertStatus(201);
    $firstVisitId = $firstResponse->json('visit.id');

    $freshCard = VisitorCard::factory()->create();
    $response = $this->postJson('/api/visits', array_merge($payload, [
        'card_ids' => [$freshCard->id],
        'close_open_visits' => false,
    ]))->assertStatus(422);

    expect($response->json('message'))->toContain('active visit');
    expect($response->json('open_visit_id'))->toBe($firstVisitId);
});

it('forbids closing open visits if caller lacks checkout_visits permission (403)', function () {
    $payload = validVisitPayload(['id_number' => '3210000000000998']);
    $this->postJson('/api/visits', $payload)->assertStatus(201);

    // Create user with only create_visits (no checkout_visits)
    $restrictedUser = User::factory()->create();
    $restrictedUser->givePermissionTo('create_visits');
    actingAsUser($restrictedUser);

    $freshCard = VisitorCard::factory()->create();
    $this->postJson('/api/visits', array_merge($payload, [
        'card_ids' => [$freshCard->id],
        'close_open_visits' => true,
    ]))->assertStatus(403)
        ->assertJsonStructure(['message']);
});

it('automatically checks out open visits and creates new visit in one atomic transaction when close_open_visits is true', function () {
    $card1 = VisitorCard::factory()->create();
    $vehicleCard1 = VehicleCard::factory()->create();
    $payload1 = validVisitPayload([
        'id_number' => '3210000000000997',
        'card_ids' => [$card1->id],
        'vehicle_card_id' => $vehicleCard1->id,
    ]);

    $firstResponse = $this->postJson('/api/visits', $payload1)->assertStatus(201);
    $firstVisitId = $firstResponse->json('visit.id');

    expect($card1->fresh()->status)->toBe('in_use');
    expect($vehicleCard1->fresh()->status)->toBe('in_use');

    // Re-use card1 and a new vehicleCard2 for the second visit, closing the previous visit
    $vehicleCard2 = VehicleCard::factory()->create();
    $payload2 = validVisitPayload([
        'id_number' => '3210000000000997',
        'card_ids' => [$card1->id],
        'vehicle_card_id' => $vehicleCard2->id,
        'close_open_visits' => true,
    ]);

    $secondResponse = $this->postJson('/api/visits', $payload2)->assertStatus(201);
    $secondVisitId = $secondResponse->json('visit.id');

    expect($secondVisitId)->not->toBe($firstVisitId);

    // First visit is checked out
    $firstVisit = Visit::find($firstVisitId);
    expect($firstVisit->checked_out_at)->not->toBeNull();
    expect($firstVisit->time_out)->not->toBeNull();

    // Old vehicleCard1 was released
    expect($vehicleCard1->fresh()->status)->toBe('available');

    // Card1 is still in_use because it was reassigned to the new visit
    expect($card1->fresh()->status)->toBe('in_use');
    expect($vehicleCard2->fresh()->status)->toBe('in_use');

    // Second visit is active
    $secondVisit = Visit::find($secondVisitId);
    expect($secondVisit->checked_out_at)->toBeNull();
});

it('records a valid visit when card_ids is omitted or empty (e.g. vehicle card only)', function () {
    $staff = Staff::factory()->create();
    $vehicle = VehicleCard::factory()->create();
    $location = Location::factory()->create();

    $response = $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210000000000881',
        'name' => 'Vehicle Only Guest',
        'staff_id' => $staff->id,
        'location_id' => $location->id,
        'card_ids' => [],
        'vehicle_card_id' => $vehicle->id,
        'purpose' => 'Quick Cargo Drop',
    ]);

    $response->assertStatus(201);
    expect($response->json('visit.visitor_cards'))->toBeEmpty();
    expect($vehicle->fresh()->status)->toBe('in_use');
});
