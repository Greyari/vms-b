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
        'phone' => 'PRIVATE-PHONE-0813',
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
        ->assertJsonPath('id', $visitor->id)
        ->assertJsonMissingPath('phone');
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
