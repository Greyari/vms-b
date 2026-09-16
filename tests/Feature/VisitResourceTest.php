<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Support\Carbon;

beforeEach(function () {
    actingAsAdmin();
});

/** A visitor whose profile carries values that must never leave the API with a visit. */
function visitorWithPrivateProfile(): Visitor
{
    return Visitor::factory()->create([
        'name' => 'Profile Owner',
        'birth_date' => '1990-01-31',
        'birth_place' => 'PRIVATE-BIRTHPLACE',
        'phone' => 'PRIVATE-PHONE-0812',
        'nationality' => 'PRIVATE-NATIONALITY',
        'certificate_no' => 'PRIVATE-CERT-77',
        'seaman_book_no' => 'PRIVATE-SEAMAN-88',
    ]);
}

function openVisitFor(Visitor $visitor): Visit
{
    $location = Location::factory()->create(['name' => 'TEST BARGE 202']);
    $visit = Visit::factory()->create([
        'visitor_id' => $visitor->id,
        'location_id' => $location->id,
        'location_name' => 'TEST BARGE 202',
        'host_name' => 'Pak Contoh',
        'visitor_company' => 'PT Contoh',
        'time_in' => '08:05:00',
    ]);
    $visit->visitorCards()->attach(VisitorCard::factory()->create(['card_no' => 'VC-90001'])->id);

    return $visit;
}

it('lists active visits without the visitor profile', function () {
    $visitor = visitorWithPrivateProfile();
    openVisitFor($visitor);

    $response = $this->getJson('/api/visits/active')->assertOk();

    expect($response->json('0.visitor.id_number'))->toBe($visitor->id_number);
    expect($response->json('0.time_in'))->toBe('08:05');
    expect($response->json('0.location_name'))->toBe('TEST BARGE 202');
    expect($response->json('0.visitor_cards.0.card_no'))->toBe('VC-90001');
    expect($response->getContent())->not->toContain('PRIVATE-');
});

it('prints a visit with the pass fields and without the visitor profile', function () {
    $visit = openVisitFor(visitorWithPrivateProfile());

    $response = $this->getJson("/api/visits/{$visit->id}/print")->assertOk();

    $response->assertJsonPath('host_name', 'Pak Contoh')
        ->assertJsonPath('visitor_company', 'PT Contoh')
        ->assertJsonPath('location.name', 'TEST BARGE 202');
    expect($response->getContent())->not->toContain('PRIVATE-');
});

it('keeps the paginator shape of the visit history and hides the profile', function () {
    openVisitFor(visitorWithPrivateProfile());

    $response = $this->getJson('/api/visits/history')->assertOk();

    $response->assertJsonStructure(['data', 'current_page', 'total', 'per_page']);
    expect($response->json('data.0.visitor.name'))->toBe('Profile Owner');
    expect($response->getContent())->not->toContain('PRIVATE-');
});

it('sends checked_out_at as local wall-clock time', function () {
    $this->travelTo(Carbon::parse('2026-09-15 06:40:00'));
    $visit = openVisitFor(visitorWithPrivateProfile());

    $this->postJson("/api/visits/{$visit->id}/checkout")
        ->assertOk()
        ->assertJsonPath('visit.checked_out_at', '2026-09-15 06:40:00');
});

it('returns the recorded visit without the visitor profile', function () {
    $visitor = visitorWithPrivateProfile();
    $staff = Staff::factory()->create();
    $card = VisitorCard::factory()->create();

    $response = $this->postJson('/api/visits', [
        'id_type' => $visitor->id_type,
        'id_number' => $visitor->id_number,
        'name' => $visitor->name,
        'staff_id' => $staff->id,
        'card_ids' => [$card->id],
        'purpose' => 'Meeting',
    ])->assertStatus(201);

    expect($response->json('visit.visitor.visitor_no'))->toBe($visitor->fresh()->visitor_no);
    expect($response->getContent())->not->toContain('PRIVATE-');
});
