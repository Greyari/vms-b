<?php

use App\Models\Visit;
use App\Models\Visitor;

it('lists the months with visits, newest first, and the newest month by default', function () {
    actingAsSecurity();
    $visitor = Visitor::factory()->create();
    Visit::factory()->checkedOut()->create(['visitor_id' => $visitor->id, 'date' => '2026-07-10', 'purpose' => 'July meeting']);
    Visit::factory()->checkedOut()->create(['visitor_id' => $visitor->id, 'date' => '2026-09-01', 'purpose' => 'September survey']);
    Visit::factory()->checkedOut()->create(['visitor_id' => $visitor->id, 'date' => '2026-09-20', 'purpose' => 'September delivery']);
    Visit::factory()->create(['date' => '2026-09-21', 'purpose' => 'Someone else']);

    $this->getJson("/api/visitors/{$visitor->id}/visits")
        ->assertOk()
        ->assertJsonPath('months', [
            ['month' => '2026-09', 'visits' => 2],
            ['month' => '2026-07', 'visits' => 1],
        ])
        ->assertJsonPath('month', '2026-09')
        ->assertJsonCount(2, 'visits')
        ->assertJsonPath('visits.0.purpose', 'September delivery')
        ->assertJsonPath('visits.1.purpose', 'September survey');
});

it('returns the visits of the requested month', function () {
    actingAsSecurity();
    $visitor = Visitor::factory()->create();
    Visit::factory()->checkedOut()->create(['visitor_id' => $visitor->id, 'date' => '2026-07-10', 'purpose' => 'July meeting']);
    Visit::factory()->checkedOut()->create(['visitor_id' => $visitor->id, 'date' => '2026-09-01', 'purpose' => 'September survey']);

    $this->getJson("/api/visitors/{$visitor->id}/visits?month=2026-07")
        ->assertOk()
        ->assertJsonPath('month', '2026-07')
        ->assertJsonCount(1, 'visits')
        ->assertJsonPath('visits.0.purpose', 'July meeting');
});

it('handles a month shorter than 31 days', function () {
    actingAsSecurity();
    $visitor = Visitor::factory()->create();
    Visit::factory()->checkedOut()->create(['visitor_id' => $visitor->id, 'date' => '2026-02-28']);
    Visit::factory()->checkedOut()->create(['visitor_id' => $visitor->id, 'date' => '2026-03-01']);

    $this->getJson("/api/visitors/{$visitor->id}/visits?month=2026-02")
        ->assertOk()
        ->assertJsonCount(1, 'visits')
        ->assertJsonPath('visits.0.date', '2026-02-28');
});

it('refuses a malformed month', function () {
    actingAsSecurity();
    $visitor = Visitor::factory()->create();

    $this->getJson("/api/visitors/{$visitor->id}/visits?month=2026-7")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['month']);
});

it('returns no months and no visits for a visitor who never came', function () {
    actingAsSecurity();
    $visitor = Visitor::factory()->create();

    $this->getJson("/api/visitors/{$visitor->id}/visits")
        ->assertOk()
        ->assertExactJson(['months' => [], 'month' => null, 'visits' => []]);
});
