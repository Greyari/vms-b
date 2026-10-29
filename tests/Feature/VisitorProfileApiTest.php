<?php

use App\Models\Location;
use App\Models\Visit;
use App\Models\Visitor;
use Illuminate\Support\Str;

it('shows the whole profile with a summary of the visits', function () {
    actingAsSecurity();
    $vessel = Location::factory()->create(['code' => 'IMO9200001', 'name' => 'MT. SAMPLE CREW SHIP']);
    $visitor = Visitor::factory()->crew($vessel)->create([
        'birth_date' => '1990-04-05',
        'seaman_book_no' => 'SB-SAMPLE-1',
    ]);
    Visit::factory()->checkedOut()->create(['visitor_id' => $visitor->id, 'date' => '2026-08-01']);
    $open = Visit::factory()->create(['visitor_id' => $visitor->id, 'date' => '2026-09-02']);

    $this->getJson("/api/visitors/{$visitor->id}")
        ->assertOk()
        ->assertJsonPath('visitor.birth_date', '1990-04-05')
        ->assertJsonPath('visitor.visitor_type', 'crew')
        ->assertJsonPath('visitor.vessel.code', 'IMO9200001')
        ->assertJsonPath('visitor.seaman_book_no', 'SB-SAMPLE-1')
        ->assertJsonPath('summary.visits_count', 2)
        ->assertJsonPath('summary.first_visit_date', '2026-08-01')
        ->assertJsonPath('summary.last_visit_date', '2026-09-02')
        ->assertJsonPath('summary.open_visit_id', $open->id);
});

it('answers 404 for an unknown visitor', function () {
    actingAsSecurity();

    $this->getJson('/api/visitors/'.Str::uuid7())->assertNotFound();
});

it('saves the whole profile on update', function () {
    actingAsAdmin();
    $vessel = Location::factory()->create();
    $visitor = Visitor::factory()->create(['id_type' => 'passport', 'id_number' => 'X1234567']);

    $this->putJson("/api/visitors/{$visitor->id}", [
        'id_type' => 'passport',
        'id_number' => 'x1234567',
        'name' => 'Sample Crew Person',
        'visitor_type' => 'crew',
        'gender' => 'M',
        'birth_date' => '1985-01-31',
        'birth_place' => 'Sample City',
        'nationality' => 'SAMPLELAND',
        'occupation' => 'Engineer',
        'id_issued_at' => '2020-01-01',
        'id_expires_at' => '2030-01-01',
        'vessel_id' => $vessel->id,
        'certificate' => 'CoC',
        'certificate_no' => 'CERT-SAMPLE-2',
        'seaman_book_no' => 'SB-SAMPLE-2',
        'seaman_book_expires_at' => '2028-06-30',
    ])->assertOk();

    $fresh = $visitor->fresh();
    expect($fresh->id_number)->toBe('X1234567')
        ->and($fresh->visitor_type->value)->toBe('crew')
        ->and($fresh->vessel_id)->toBe($vessel->id)
        ->and($fresh->birth_date->format('Y-m-d'))->toBe('1985-01-31')
        ->and($fresh->seaman_book_no)->toBe('SB-SAMPLE-2');
});

it('keeps the profile fields an update does not send', function () {
    actingAsAdmin();
    $visitor = Visitor::factory()->create(['occupation' => 'Surveyor']);

    $this->putJson("/api/visitors/{$visitor->id}", [
        'id_type' => $visitor->id_type,
        'id_number' => $visitor->id_number,
        'name' => 'Renamed Sample',
    ])->assertOk();

    expect($visitor->fresh()->occupation)->toBe('Surveyor');
});

it('refuses an unknown visitor type, gender or vessel', function () {
    actingAsAdmin();
    $place = Location::factory()->place('SAMPLE YARD')->create();
    $visitor = Visitor::factory()->create();

    $this->putJson("/api/visitors/{$visitor->id}", [
        'id_type' => $visitor->id_type,
        'id_number' => $visitor->id_number,
        'name' => $visitor->name,
        'visitor_type' => 'black_list',
        'gender' => 'X',
        'vessel_id' => $place->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['visitor_type', 'gender', 'vessel_id']);
});
