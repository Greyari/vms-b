<?php

use App\Models\Location;
use App\Models\VesselArrival;
use App\Models\Visitor;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('creates a vessel arrival and links crew through the pivot table', function () {
    $vessel = Location::factory()->create(['name' => 'MV. OCEAN STAR']);
    $arrival = VesselArrival::factory()->create([
        'location_id' => $vessel->id,
        'vessel_name' => $vessel->name,
    ]);

    $crew1 = Visitor::factory()->crew()->create(['name' => 'Captain Jack']);
    $crew2 = Visitor::factory()->crew()->create(['name' => 'Chief Engineer Bob']);

    $arrival->crew()->attach([$crew1->id, $crew2->id]);

    expect($arrival->vessel->id)->toBe($vessel->id);
    expect($arrival->crew)->toHaveCount(2);
    expect($arrival->crew->pluck('name'))->toContain('Captain Jack', 'Chief Engineer Bob');
});

it('deleting a vessel arrival cascades to the pivot table but leaves the crew visitors intact', function () {
    $arrival = VesselArrival::factory()->create();
    $crew = Visitor::factory()->crew()->create();

    $arrival->crew()->attach($crew->id);

    expect(DB::table('vessel_arrival_crew')->where('vessel_arrival_id', $arrival->id)->count())->toBe(1);

    $arrival->delete();

    expect(DB::table('vessel_arrival_crew')->where('vessel_arrival_id', $arrival->id)->count())->toBe(0);
    expect(Visitor::find($crew->id))->not->toBeNull();
});

it('refuses to delete a location that has vessel arrivals due to restrict foreign key', function () {
    $vessel = Location::factory()->create();
    $arrival = VesselArrival::factory()->create(['location_id' => $vessel->id]);

    expect(fn () => $vessel->forceDelete())
        ->toThrow(QueryException::class);
});

it('supports signedOut state factory', function () {
    $arrival = VesselArrival::factory()->signedOut('2026-09-20')->create();

    expect($arrival->sign_out_date->format('Y-m-d'))->toBe('2026-09-20');
    expect($arrival->sign_out_port)->toBe('SINGAPORE');
});
