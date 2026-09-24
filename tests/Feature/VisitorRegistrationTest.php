<?php

use App\Models\Location;
use App\Models\Visitor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('registers a visitor with a full profile and the next visitor number', function () {
    actingAsSecurity();
    $vessel = Location::factory()->create();

    $this->postJson('/api/visitors', [
        'id_type' => 'passport',
        'id_number' => 'ab-123 456',
        'name' => 'Sample New Crew',
        'company' => 'PT Contoh Pelayaran',
        'visitor_type' => 'crew',
        'gender' => 'F',
        'birth_date' => '1992-02-29',
        'nationality' => 'SAMPLELAND',
        'vessel_id' => $vessel->id,
        'seaman_book_no' => 'SB-SAMPLE-3',
    ])->assertCreated()
        ->assertJsonPath('visitor.visitor_no', 1_000_000)
        ->assertJsonPath('visitor.id_number', 'AB123456')
        ->assertJsonPath('visitor.visitor_type', 'crew')
        ->assertJsonPath('visitor.is_blacklisted', false);

    expect(Visitor::sole()->seaman_book_no)->toBe('SB-SAMPLE-3');
});

it('refuses an identity that is already registered, in any spelling', function () {
    actingAsSecurity();
    Visitor::factory()->create(['id_type' => 'ktp', 'id_number' => '3210000000000801']);

    $this->postJson('/api/visitors', ['id_type' => 'ktp', 'id_number' => '3210-0000-0000-0801', 'name' => 'Sample Twin'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['id_number']);
});

it('answers 422 when the same person is registered between validation and insert', function () {
    actingAsSecurity();

    // Another request inserts the same identity inside this request's
    // transaction, just before its own insert.
    raceVisitorNoOnCreating(function (Visitor $visitor): void {
        DB::table('visitors')->insert([
            'id' => (string) Str::uuid7(),
            'id_type' => $visitor->id_type,
            'id_number' => $visitor->id_number,
            'name' => 'Concurrent Registration',
            'visitor_no' => $visitor->visitor_no + 1,
            'is_blacklisted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $this->postJson('/api/visitors', ['id_type' => 'ktp', 'id_number' => '3210000000000802', 'name' => 'Sample Racer'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['id_number']);

        expect(Visitor::count())->toBe(0);
    } finally {
        Visitor::flushEventListeners();
        Visitor::clearBootedModels();
    }
});

it('retries a registration that loses a visitor number race', function () {
    actingAsSecurity();
    $raced = false;
    raceVisitorNoOnCreating(function (Visitor $visitor) use (&$raced): void {
        if ($raced) {
            return;
        }
        $raced = true;
        DB::table('visitors')->insert([
            'id' => (string) Str::uuid7(),
            'id_type' => 'ktp',
            'id_number' => 'RACEDECOY'.$visitor->visitor_no,
            'name' => 'Race Decoy',
            'visitor_no' => $visitor->visitor_no,
            'is_blacklisted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $this->postJson('/api/visitors', ['id_type' => 'ktp', 'id_number' => '3210000000000803', 'name' => 'Sample Retry'])
            ->assertCreated();

        expect(Visitor::count())->toBe(1)
            ->and(Visitor::where('name', 'Race Decoy')->exists())->toBeFalse();
    } finally {
        Visitor::flushEventListeners();
        Visitor::clearBootedModels();
    }
});

it('creates the visitor inside its own transaction', function () {
    actingAsSecurity();
    $baseLevel = DB::transactionLevel();
    $levelWhenCreating = null;
    raceVisitorNoOnCreating(function () use (&$levelWhenCreating): void {
        $levelWhenCreating = DB::transactionLevel();
    });

    try {
        $this->postJson('/api/visitors', ['id_type' => 'sim', 'id_number' => 'SIM0000001', 'name' => 'Sample Driver'])
            ->assertCreated();

        // RefreshDatabase already holds one transaction; the endpoint must open its own.
        expect($levelWhenCreating)->toBeGreaterThan($baseLevel);
    } finally {
        Visitor::flushEventListeners();
        Visitor::clearBootedModels();
    }
});
