<?php

use App\Models\Staff;

it('creates a staff member with the full profile and no department', function () {
    actingAsAdmin();

    $this->postJson('/api/staffs', [
        'staff_id' => 'STF-900001',
        'name' => 'Sample Host',
        'gender' => 'F',
        'birth_date' => '1980-12-01',
        'company' => 'PT Contoh Galangan',
        'address' => 'Jalan Contoh 1',
        'occupation' => 'Planner',
        'is_active' => false,
    ])->assertCreated()
        ->assertJsonPath('department', null)
        ->assertJsonPath('company', 'PT Contoh Galangan')
        ->assertJsonPath('is_active', false);
});

it('refuses an unknown gender', function () {
    actingAsAdmin();

    $this->postJson('/api/staffs', ['staff_id' => 'STF-900002', 'name' => 'Sample', 'gender' => 'X'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['gender']);
});

it('lists staff filtered by status and company and searched by name or number', function () {
    actingAsSecurity();
    Staff::factory()->create(['staff_id' => 'STF-910001', 'name' => 'Active Contoh', 'company' => 'PT Contoh Galangan']);
    Staff::factory()->create(['staff_id' => 'STF-910002', 'name' => 'Inactive Contoh', 'company' => 'PT Contoh Galangan', 'is_active' => false]);
    Staff::factory()->create(['staff_id' => 'STF-910003', 'name' => 'Other Firm', 'company' => 'PT Lain']);

    $this->getJson('/api/staffs?is_active=1&company=contoh')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.staff_id', 'STF-910001');

    $this->getJson('/api/staffs?search=stf-91000')
        ->assertOk()
        ->assertJsonPath('total', 3);
});

it('exports the filtered staff with their profile', function () {
    actingAsAdmin();
    Staff::factory()->create(['staff_id' => 'STF-920001', 'name' => 'Exported Host', 'company' => 'PT Contoh Galangan', 'occupation' => 'Planner']);
    Staff::factory()->create(['staff_id' => 'STF-920002', 'name' => 'Left Out', 'company' => 'PT Lain']);

    $rows = exportedRows($this->get('/api/staffs/export?company=contoh')->assertOk());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Staff ID'])->toBe('STF-920001')
        ->and($rows[0]['Occupation'])->toBe('Planner')
        ->and($rows[0]['Status'])->toBe('Active');
});

it('lets security list staff but not export them', function () {
    actingAsSecurity();

    $this->getJson('/api/staffs')->assertOk();
    $this->getJson('/api/staffs/export')->assertStatus(403);
});
