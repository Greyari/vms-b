<?php

use App\Models\VisitorCard;
use Illuminate\Http\UploadedFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

it('creates a card with its legacy attributes as available and refuses a status', function () {
    actingAsAdmin();

    $this->postJson('/api/cards', [
        'card_no' => 'VC-90001',
        'absenteeism_no' => 'ABS-90001',
        'card_type' => 'visitor_green',
        'note' => 'Green lanyard',
    ])->assertCreated()
        ->assertJsonPath('status', 'available')
        ->assertJsonPath('card_type', 'visitor_green')
        ->assertJsonPath('is_active', true);

    $this->postJson('/api/cards', ['card_no' => 'VC-90002', 'status' => 'in_use'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['status']);
});

it('refuses an unknown card type', function () {
    actingAsAdmin();

    $this->postJson('/api/cards', ['card_no' => 'VC-90003', 'card_type' => 'gold'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['card_type']);
});

it('keeps the status when a card in use is edited', function () {
    actingAsAdmin();
    $card = VisitorCard::factory()->inUse()->create(['card_no' => 'VC-90004']);

    $this->putJson("/api/cards/{$card->id}", ['card_no' => 'VC-90004', 'note' => 'Replaced strap'])
        ->assertOk();

    expect($card->fresh()->status)->toBe('in_use')
        ->and($card->fresh()->note)->toBe('Replaced strap');
});

it('searches one card number or a range, ignoring case', function () {
    actingAsSecurity();
    foreach (['vc-00001', 'VC-00002', 'VC-00003', 'VC-00010'] as $number) {
        VisitorCard::factory()->create(['card_no' => $number]);
    }

    $this->getJson('/api/cards?from=VC-00002&to=vc-00003')
        ->assertOk()
        ->assertJsonPath('total', 2);

    $this->getJson('/api/cards?from=vc-00001&to=VC-00001')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.card_no', 'vc-00001');

    $this->getJson('/api/cards?search=00010')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.card_no', 'VC-00010');
});

it('filters cards by type and active flag', function () {
    actingAsSecurity();
    VisitorCard::factory()->create(['card_no' => 'VC-91001', 'card_type' => 'crew']);
    VisitorCard::factory()->create(['card_no' => 'VC-91002', 'card_type' => 'crew', 'is_active' => false]);
    VisitorCard::factory()->create(['card_no' => 'VC-91003', 'card_type' => 'special']);

    $this->getJson('/api/cards?card_type=crew&is_active=true')
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.card_no', 'VC-91001');
});

it('refuses to delete a card in use and deletes an available one', function () {
    actingAsAdmin();
    $inUse = VisitorCard::factory()->inUse()->create();
    $free = VisitorCard::factory()->create();

    $this->deleteJson("/api/cards/{$inUse->id}")->assertStatus(422);
    $this->deleteJson("/api/cards/{$free->id}")->assertOk();

    expect(VisitorCard::find($inUse->id))->not->toBeNull()
        ->and(VisitorCard::find($free->id))->toBeNull();
});

it('imports cards without touching the status of existing ones', function () {
    actingAsAdmin();
    VisitorCard::factory()->inUse()->create(['card_no' => 'VC-92001']);

    $base = tempnam(sys_get_temp_dir(), 'vmscards_');
    $path = $base.'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRow(['card_no' => 'VC-92001', 'status' => 'available']);
    $writer->addRow(['card_no' => 'VC-92002', 'status' => 'in_use']);
    $writer->close();

    try {
        $this->post('/api/cards/import', ['file' => new UploadedFile($path, 'cards.xlsx', null, null, true)])
            ->assertOk();
    } finally {
        @unlink($path);
        @unlink($base);
    }

    expect(VisitorCard::where('card_no', 'VC-92001')->value('status'))->toBe('in_use')
        ->and(VisitorCard::where('card_no', 'VC-92002')->value('status'))->toBe('available');
});

it('exports the filtered cards', function () {
    actingAsAdmin();
    VisitorCard::factory()->create(['card_no' => 'VC-93001', 'card_type' => 'crew', 'absenteeism_no' => 'ABS-93001']);
    VisitorCard::factory()->create(['card_no' => 'VC-93002', 'card_type' => 'special']);

    $rows = exportedRows($this->get('/api/cards/export?card_type=crew')->assertOk());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['Card No'])->toBe('VC-93001')
        ->and($rows[0]['Absenteeism No'])->toBe('ABS-93001')
        ->and($rows[0]['Status'])->toBe('available');
});

it('lets security list cards but not export them', function () {
    actingAsSecurity();

    $this->getJson('/api/cards')->assertOk();
    $this->getJson('/api/cards/export')->assertStatus(403);
});

it('creates and updates cards with id_card_no, name, date_issued, lost and damaged status', function () {
    actingAsAdmin();

    $res = $this->postJson('/api/cards', [
        'card_no' => 'ASL-9999',
        'id_card_no' => '0001234567',
        'name' => 'VISITOR GREEN',
        'date_issued' => '2023-07-22',
        'status' => 'lost',
        'note' => 'Hilang di dermaga 1',
    ])->assertCreated()
        ->assertJsonPath('card_no', 'ASL-9999')
        ->assertJsonPath('id_card_no', '0001234567')
        ->assertJsonPath('name', 'VISITOR GREEN')
        ->assertJsonPath('status', 'lost')
        ->assertJsonPath('note', 'Hilang di dermaga 1');

    $id = $res->json('id');

    $this->putJson("/api/cards/{$id}", [
        'card_no' => 'ASL-9999',
        'status' => 'damaged',
    ])->assertOk()
        ->assertJsonPath('status', 'damaged');

    $this->getJson('/api/cards?status=damaged')
        ->assertOk()
        ->assertJsonPath('data.0.card_no', 'ASL-9999');
});
