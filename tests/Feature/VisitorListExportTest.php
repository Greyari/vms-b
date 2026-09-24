<?php

use App\Models\Location;
use App\Models\Visitor;

it('filters the visitor list by visitor type', function () {
    actingAsSecurity();
    Visitor::factory()->create(['name' => 'Sample Supplier', 'visitor_type' => 'supplier']);
    Visitor::factory()->create(['name' => 'Sample Guest']);

    $this->getJson('/api/visitors?visitor_type=supplier')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.name', 'Sample Supplier');
});

it('refuses an unknown visitor type filter', function () {
    actingAsSecurity();

    $this->getJson('/api/visitors?visitor_type=black_list')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['visitor_type']);
});

it('sorts the visitor list by visitor number', function () {
    actingAsSecurity();
    $first = Visitor::factory()->create(['name' => 'Zed Sample']);
    $second = Visitor::factory()->create(['name' => 'Abe Sample']);

    $this->getJson('/api/visitors?sort=visitor_no&dir=desc')
        ->assertOk()
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.id', $first->id);
});

it('exports the filtered visitors with their profile columns', function () {
    actingAsAdmin();
    $vessel = Location::factory()->create(['code' => 'IMO9400001']);
    Visitor::factory()->crew($vessel)->create([
        'name' => 'Sample Crew One',
        'phone' => '0800-0000-0004',
        'seaman_book_no' => 'SB-SAMPLE-4',
    ]);
    Visitor::factory()->create(['name' => 'Sample Supplier', 'visitor_type' => 'supplier']);

    $rows = exportedRows($this->get('/api/visitors/export?visitor_type=crew')->assertOk());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Name'])->toBe('Sample Crew One')
        ->and($rows[0]['Visitor Type'])->toBe('crew')
        ->and($rows[0]['Phone'])->toBe('0800-0000-0004')
        ->and($rows[0]['Vessel'])->toBe('IMO9400001')
        ->and($rows[0]['Seaman Book No'])->toBe('SB-SAMPLE-4');
});

it('keeps the visitor export to supervisors and administrators', function () {
    actingAsSecurity();

    $this->getJson('/api/visitors/export')->assertStatus(403);
});
