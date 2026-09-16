<?php

use App\Models\ImportMapping;
use App\Models\Nationality;
use App\Models\VesselArrival;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('attaches crew to a vessel arrival through a uuid pivot', function () {
    $arrival = VesselArrival::factory()->create();
    $crew = Visitor::factory()->count(2)->crew($arrival->vessel)->create();

    $arrival->crew()->sync($crew->pluck('id')->all());

    expect($arrival->crew()->count())->toBe(2);
    expect(DB::table('vessel_arrival_crew')->pluck('id')->every(fn ($id) => Str::isUuid($id)))->toBeTrue();
});

it('refuses to hard-delete a vessel that has arrivals', function () {
    $arrival = VesselArrival::factory()->create();

    expect(fn () => $arrival->vessel->forceDelete())->toThrow(QueryException::class);
});

it('keeps nationality names unique', function () {
    Nationality::create(['name' => 'Indonesia']);

    expect(fn () => Nationality::create(['name' => 'Indonesia']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('keeps one mapping per source, entity and source key', function () {
    $attributes = [
        'source' => 'vms-old',
        'entity' => 'visitor',
        'source_key' => '347414',
        'target_id' => (string) Str::uuid7(),
        'imported_at' => now(),
    ];
    ImportMapping::create($attributes);

    expect(fn () => ImportMapping::create(array_merge($attributes, ['target_id' => (string) Str::uuid7()])))
        ->toThrow(UniqueConstraintViolationException::class);
});
