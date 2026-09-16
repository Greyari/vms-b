<?php

use App\Enums\VisitorType;
use App\Models\Location;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('gives the first new visitor the configured floor number', function () {
    expect(Visitor::factory()->create()->visitor_no)->toBe(1_000_000);
});

it('numbers later visitors consecutively above the floor', function () {
    $first = Visitor::factory()->create();
    $second = Visitor::factory()->create();

    expect($second->visitor_no)->toBe($first->visitor_no + 1);
});

it('ignores imported legacy numbers below the floor when numbering', function () {
    Visitor::factory()->create(['visitor_no' => 347_414]);

    expect(Visitor::factory()->create()->visitor_no)->toBe(1_000_000);
});

it('keeps an explicitly given visitor_no', function () {
    expect(Visitor::factory()->create(['visitor_no' => 12_345])->visitor_no)->toBe(12_345);
});

it('defaults the visitor type and casts profile fields', function () {
    $visitor = Visitor::factory()->create([
        'birth_date' => '1990-05-17',
        'id_lifetime' => true,
    ])->fresh();

    expect($visitor->visitor_type)->toBe(VisitorType::Visitor);
    expect($visitor->birth_date->toDateString())->toBe('1990-05-17');
    expect($visitor->id_lifetime)->toBeTrue();
});

it('links a crew member to a vessel and lists the crew from the vessel', function () {
    $vessel = Location::factory()->create();
    $crew = Visitor::factory()->crew($vessel)->create();

    expect($crew->vessel->is($vessel))->toBeTrue();
    expect($vessel->crew()->pluck('id')->all())->toContain($crew->id);
});

it('backfills visitor_no for rows that predate the column, oldest first', function () {
    $row = fn (string $name, string $idNumber, $createdAt) => [
        'id' => (string) Str::uuid7(),
        'id_type' => 'ktp',
        'id_number' => $idNumber,
        'name' => $name,
        'is_blacklisted' => false,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ];

    DB::table('visitors')->insert([
        $row('Newer', '9000000000000002', now()),
        $row('Older', '9000000000000001', now()->subDay()),
    ]);

    (require database_path('migrations/2026_09_13_100400_backfill_visitor_no.php'))->up();

    expect((int) DB::table('visitors')->where('name', 'Older')->value('visitor_no'))->toBe(1_000_000);
    expect((int) DB::table('visitors')->where('name', 'Newer')->value('visitor_no'))->toBe(1_000_001);
});
