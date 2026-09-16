<?php

use App\Enums\LocationType;
use App\Models\Location;
use Illuminate\Database\UniqueConstraintViolationException;

it('generates the legacy-style code for a place created without one', function () {
    $location = Location::factory()->place('Production Office')->create();

    expect($location->code)->toBe('PRODUCTION_OFFICE');
    expect($location->type)->toBe(LocationType::Location);
});

it('keeps the code when a place is renamed', function () {
    $location = Location::factory()->place('Store')->create();

    $location->update(['name' => 'Store ASL']);

    expect($location->fresh()->code)->toBe('STORE');
});

it('keeps an explicit vessel IMO code as given', function () {
    $vessel = Location::factory()->create(['code' => '9123456', 'name' => 'MT. SAMPLE TANKER']);

    expect($vessel->code)->toBe('9123456');
    expect($vessel->type)->toBe(LocationType::RepairVessel);
});

it('refuses two locations with the same code', function () {
    Location::factory()->create(['code' => '9000001']);

    expect(fn () => Location::factory()->create(['code' => '9000001']))
        ->toThrow(UniqueConstraintViolationException::class);
});
