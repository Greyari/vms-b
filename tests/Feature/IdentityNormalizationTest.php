<?php

use App\Models\Staff;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    actingAsAdmin();
});

it('stores a normalized id_number when a visit registers a new visitor', function () {
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    $this->postJson('/api/visits', [
        'id_type' => 'ktp',
        'id_number' => '3210-0000 0000 0001',
        'name' => 'Spaced Out',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ])->assertStatus(201);

    expect(Visitor::where('id_number', '3210000000000001')->exists())->toBeTrue();
});

it('treats a differently punctuated number as the same visitor', function () {
    Visitor::factory()->create(['id_type' => 'passport', 'id_number' => 'A1234567', 'name' => 'Existing']);
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    $this->postJson('/api/visits', [
        'id_type' => 'passport',
        'id_number' => ' a-1234567 ',
        'name' => 'Typed Differently',
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ])->assertStatus(201);

    expect(Visitor::where('id_type', 'passport')->count())->toBe(1);
});

it('finds a visitor when the search term carries punctuation', function () {
    Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '3210000000000077', 'name' => 'Findable']);

    $response = $this->getJson('/api/visits/search?q='.urlencode('3210-0000 0000 0077'));

    $response->assertOk();
    expect(collect($response->json())->pluck('name'))->toContain('Findable');
});

it('still finds a visitor by name', function () {
    Visitor::factory()->create(['name' => 'Budi Santoso']);

    $response = $this->getJson('/api/visits/search?q=budi');

    expect(collect($response->json())->pluck('name'))->toContain('Budi Santoso');
});

it('normalizes the id_number on update and rejects a punctuated duplicate', function () {
    Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '1111111111111111']);
    $b = Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '2222222222222222']);

    $this->putJson("/api/visitors/{$b->id}", [
        'id_type' => 'ktp',
        'id_number' => '1111-1111-1111-1111',
        'name' => $b->name,
    ])->assertStatus(422);

    $this->putJson("/api/visitors/{$b->id}", [
        'id_type' => 'ktp',
        'id_number' => '2222 2222 2222 2229',
        'name' => $b->name,
    ])->assertOk();

    expect($b->fresh()->id_number)->toBe('2222222222222229');
});

it('does not treat re-punctuating a blacklisted visitor number as an identity change', function () {
    $visitor = Visitor::factory()->blacklisted()->create(['id_type' => 'ktp', 'id_number' => '3333333333333333']);

    $this->putJson("/api/visitors/{$visitor->id}", [
        'id_type' => 'ktp',
        'id_number' => '3333-3333-3333-3333',
        'name' => 'Renamed',
    ])->assertOk();
});

it('normalizes stored numbers and leaves a clashing row untouched', function () {
    $row = fn (string $name, string $idNumber) => [
        'id' => (string) Str::uuid7(),
        'id_type' => 'ktp',
        'id_number' => $idNumber,
        'name' => $name,
        'is_blacklisted' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('visitors')->insert([
        $row('A', '3210-0000-0000-0005'),
        $row('B', '3210000000000006'),
        $row('C', '3210 0000 0000 0006'),
    ]);

    (require database_path('migrations/2026_09_13_100100_normalize_visitor_id_numbers.php'))->up();

    expect(DB::table('visitors')->where('name', 'A')->value('id_number'))->toBe('3210000000000005');
    expect(DB::table('visitors')->where('name', 'C')->value('id_number'))->toBe('3210 0000 0000 0006');
});

it('does not read an unnormalized stored number of a blacklisted visitor as an identity change', function () {
    $id = (string) Str::uuid7();
    DB::table('visitors')->insert([
        'id' => $id,
        'id_type' => 'ktp',
        'id_number' => '3333-3333-3333-3334',
        'name' => 'Stored Raw',
        'is_blacklisted' => true,
        'blacklist_reason' => 'Synthetic reason',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->putJson("/api/visitors/{$id}", [
        'id_type' => 'ktp',
        'id_number' => '3333 3333 3333 3334',
        'name' => 'Stored Raw',
    ])->assertOk();

    expect(DB::table('visitors')->where('id', $id)->value('id_number'))->toBe('3333333333333334');
});
