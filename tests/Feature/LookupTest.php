<?php

use App\Models\Nationality;
use App\Models\Visit;
use App\Models\Visitor;

it('lists nationality names in alphabetical order', function () {
    actingAsSecurity();
    Nationality::create(['name' => 'SINGAPORE']);
    Nationality::create(['name' => 'INDONESIA']);

    $this->getJson('/api/lookups/nationalities')
        ->assertOk()
        ->assertExactJson(['INDONESIA', 'SINGAPORE']);
});

it('suggests distinct visitor companies that start with the typed text, ignoring case', function () {
    actingAsSecurity();
    Visitor::factory()->create(['company' => 'PT Contoh Maju']);
    Visitor::factory()->create(['company' => 'PT Contoh Maju']);
    Visitor::factory()->create(['company' => 'PT Contoh Jaya']);
    Visitor::factory()->create(['company' => 'CV Lain']);

    $this->getJson('/api/lookups/companies?search=pt contoh')
        ->assertOk()
        ->assertExactJson(['PT Contoh Jaya', 'PT Contoh Maju']);
});

it('returns no company suggestions for an empty search', function () {
    actingAsSecurity();
    Visitor::factory()->create(['company' => 'PT Contoh Maju']);

    $this->getJson('/api/lookups/companies')->assertOk()->assertExactJson([]);
});

it('suggests the most used purposes of the last 90 days first', function () {
    actingAsSecurity();
    Visit::factory()->count(3)->create(['purpose' => 'Meeting', 'date' => now()->subDays(5)->toDateString()]);
    Visit::factory()->create(['purpose' => 'Delivery', 'date' => now()->subDays(10)->toDateString()]);
    Visit::factory()->count(5)->create(['purpose' => 'Old Survey', 'date' => now()->subDays(120)->toDateString()]);

    $this->getJson('/api/lookups/purposes')
        ->assertOk()
        ->assertExactJson(['Meeting', 'Delivery']);
});

it('lists the card types with their labels', function () {
    actingAsSecurity();

    $this->getJson('/api/lookups/card-types')
        ->assertOk()
        ->assertJsonCount(7)
        ->assertJsonFragment(['value' => 'visitor_green', 'label' => 'Visitor (green)']);
});

it('refuses lookups without a signed-in user', function () {
    $this->getJson('/api/lookups/card-types')->assertStatus(401);
});
