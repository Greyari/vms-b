<?php

use App\Models\Visit;
use App\Models\Visitor;

beforeEach(function () {
    actingAsAdmin();
});

function profiledVisitor(array $overrides = []): Visitor
{
    return Visitor::factory()->create(array_merge([
        'name' => 'Summary Person',
        'id_number' => '3210000000000321',
        'gender' => 'F',
        'birth_date' => '1991-02-03',
        'visitor_type' => 'supplier',
        'nationality' => 'PRIVATE-NATIONALITY',
        'seaman_book_no' => 'PRIVATE-SEAMAN-99',
    ], $overrides));
}

it('returns the summary fields from the check-in search', function () {
    $visitor = profiledVisitor();

    $response = $this->getJson('/api/visits/search?q=summary')->assertOk();

    expect($response->json('0'))->toMatchArray([
        'id' => $visitor->id,
        'visitor_no' => $visitor->visitor_no,
        'birth_date' => '1991-02-03',
        'gender' => 'F',
        'visitor_type' => 'supplier',
        'is_blacklisted' => false,
    ]);
    expect($response->getContent())->not->toContain('PRIVATE-');
});

it('shows a blacklisted visitor in the search with the reason', function () {
    Visitor::factory()->blacklisted('Synthetic reason')->create(['name' => 'Barred Summary']);

    $response = $this->getJson('/api/visits/search?q=barred')->assertOk();

    expect($response->json('0.is_blacklisted'))->toBeTrue();
    expect($response->json('0.blacklist_reason'))->toBe('Synthetic reason');
});

it('finds a visitor by a punctuated number through check-ktp', function () {
    $visitor = profiledVisitor();

    $this->getJson('/api/visitors/check-ktp/3210-0000-0000-0321')
        ->assertOk()
        ->assertJsonPath('data.id', $visitor->id)
        ->assertJsonMissingPath('data.phone');
});

it('lists visitors with number, type and visit count but without the profile', function () {
    $visitor = profiledVisitor();
    Visit::factory()->count(2)->create(['visitor_id' => $visitor->id]);

    $response = $this->getJson('/api/visitors?search=summary')->assertOk();

    expect($response->json('data.0'))->toMatchArray([
        'visitor_no' => $visitor->visitor_no,
        'visitor_type' => 'supplier',
        'visits_count' => 2,
    ]);
    expect($response->getContent())->not->toContain('PRIVATE-');
});

it('returns the summary after an update', function () {
    $visitor = profiledVisitor();

    $response = $this->putJson("/api/visitors/{$visitor->id}", [
        'id_type' => 'ktp',
        'id_number' => $visitor->id_number,
        'name' => 'Summary Renamed',
    ])->assertOk();

    expect($response->json('visitor.name'))->toBe('Summary Renamed');
    expect($response->getContent())->not->toContain('PRIVATE-');
});

it('sends a missing birth date as null', function () {
    Visitor::factory()->create(['id_number' => '3210000000000901']);

    $this->getJson('/api/visitors/check-ktp/3210000000000901')
        ->assertOk()
        ->assertJsonPath('data.birth_date', null);
});

it('leaves the visit count out of check-ktp and the check-in search', function () {
    Visitor::factory()->create(['id_number' => '3210000000000902', 'name' => 'Sample Counted']);

    expect($this->getJson('/api/visitors/check-ktp/3210000000000902')->json('data'))->not->toHaveKey('visits_count')
        ->and($this->getJson('/api/visits/search?q=Sample Counted')->json('0'))->not->toHaveKey('visits_count');
});

it('returns data null with 200 when check-ktp does not find the visitor', function () {
    $response = $this->getJson('/api/visitors/check-ktp/9999999999999999');

    // global_blacklist: hybrid spec §6.2, empty when the mirror has no active entry for the number.
    $response->assertOk()
        ->assertExactJson(['data' => null, 'global_blacklist' => []]);
});
