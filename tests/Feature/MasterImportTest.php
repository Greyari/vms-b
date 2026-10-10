<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\VisitorCard;
use Illuminate\Http\UploadedFile;
use Spatie\SimpleExcel\SimpleExcelWriter;

it('downloads templates for all master import types', function (string $type, string $uri) {
    actingAsAdmin();

    $response = $this->get($uri);
    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');
})->with([
    ['card', '/api/cards/template'],
    ['vehicle_card', '/api/vehicle-cards/template'],
    ['staff', '/api/staffs/template'],
    ['location', '/api/locations/template'],
]);

it('previews cards and flags existing cards as duplicate', function () {
    actingAsAdmin();
    VisitorCard::factory()->create(['card_no' => 'VC-PREV-01']);

    $base = tempnam(sys_get_temp_dir(), 'vms_card_');
    $path = $base.'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRow(['card_no' => 'VC-PREV-01', 'card_type' => 'visitor']);
    $writer->addRow(['card_no' => 'VC-PREV-02', 'card_type' => 'vip']);
    $writer->close();

    try {
        $file = new UploadedFile($path, 'cards.xlsx', null, null, true);
        $res = $this->postJson('/api/cards/import-preview', ['file' => $file])->assertOk()->json();

        expect($res['total_rows'])->toBe(2)
            ->and($res['duplicate_count'])->toBe(1)
            ->and($res['new_count'])->toBe(1)
            ->and($res['duplicates'][0]['identifier'])->toBe('VC-PREV-01');
    } finally {
        @unlink($path);
        @unlink($base);
    }
});

it('imports cards with replace and update actions', function () {
    actingAsAdmin();
    $card = VisitorCard::factory()->create(['card_no' => 'VC-ACT-01', 'note' => 'Original Note']);

    $base = tempnam(sys_get_temp_dir(), 'vms_card_');
    $path = $base.'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRow(['card_no' => 'VC-ACT-01', 'note' => 'Updated Note']);
    $writer->addRow(['card_no' => 'VC-ACT-02', 'note' => 'Brand New']);
    $writer->close();

    try {
        $file = new UploadedFile($path, 'cards.xlsx', null, null, true);
        $res = $this->post('/api/cards/import', [
            'file' => $file,
            'duplicate_action' => 'update',
        ])->assertOk()->json();

        expect($res['success'])->toBe(2)
            ->and($res['created'])->toBe(1)
            ->and($res['updated'])->toBe(1);

        expect($card->fresh()->note)->toBe('Updated Note');
        expect(VisitorCard::where('card_no', 'VC-ACT-02')->exists())->toBeTrue();
    } finally {
        @unlink($path);
        @unlink($base);
    }
});

it('previews and imports vehicle cards', function () {
    actingAsAdmin();
    VehicleCard::create(['card_no' => 'VH-PREV-01', 'status' => 'available']);

    $base = tempnam(sys_get_temp_dir(), 'vms_vh_');
    $path = $base.'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRow(['card_no' => 'VH-PREV-01', 'status' => 'in_use']);
    $writer->addRow(['card_no' => 'VH-PREV-02', 'status' => 'available']);
    $writer->close();

    try {
        $file = new UploadedFile($path, 'vehicles.xlsx', null, null, true);
        $preview = $this->postJson('/api/vehicle-cards/import-preview', ['file' => $file])->assertOk()->json();
        expect($preview['duplicate_count'])->toBe(1)
            ->and($preview['new_count'])->toBe(1);

        $import = $this->post('/api/vehicle-cards/import', [
            'file' => $file,
            'duplicate_action' => 'replace',
        ])->assertOk()->json();

        expect($import['success'])->toBe(2)
            ->and($import['replaced'])->toBe(1)
            ->and($import['created'])->toBe(1);

        expect(VehicleCard::where('card_no', 'VH-PREV-01')->value('status'))->toBe('in_use');
    } finally {
        @unlink($path);
        @unlink($base);
    }
});

it('previews and imports staff with duplicate detection', function () {
    actingAsAdmin();
    Staff::create([
        'staff_id' => 'STF-EX-01',
        'name' => 'Original Staff',
        'department' => 'IT',
    ]);

    $base = tempnam(sys_get_temp_dir(), 'vms_stf_');
    $path = $base.'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRow([
        'staff_id' => 'STF-EX-01',
        'name' => 'Updated Staff',
        'department' => 'HR',
    ]);
    $writer->addRow([
        'staff_id' => 'STF-EX-02',
        'name' => 'New Staff',
        'department' => 'Finance',
    ]);
    $writer->close();

    try {
        $file = new UploadedFile($path, 'staffs.xlsx', null, null, true);
        $preview = $this->postJson('/api/staffs/import-preview', ['file' => $file])->assertOk()->json();
        expect($preview['duplicate_count'])->toBe(1)
            ->and($preview['new_count'])->toBe(1);

        $import = $this->post('/api/staffs/import', [
            'file' => $file,
            'duplicate_action' => 'update',
        ])->assertOk()->json();

        expect($import['success'])->toBe(2)
            ->and($import['updated'])->toBe(1)
            ->and($import['created'])->toBe(1);

        expect(Staff::where('staff_id', 'STF-EX-01')->value('name'))->toBe('Updated Staff');
        expect(Staff::where('staff_id', 'STF-EX-01')->value('department'))->toBe('HR');
        expect(Staff::where('staff_id', 'STF-EX-02')->exists())->toBeTrue();
    } finally {
        @unlink($path);
        @unlink($base);
    }
});

it('previews and imports locations & vessels', function () {
    actingAsAdmin();
    Location::create([
        'name' => 'Dermaga Barat Lama',
        'type' => 'location',
        'code' => 'DERMAGA_BARAT_LAMA',
    ]);

    $base = tempnam(sys_get_temp_dir(), 'vms_loc_');
    $path = $base.'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRow([
        'name' => 'Dermaga Barat Lama',
        'type' => 'location',
        'code' => '',
        'pic' => 'Pak Budi',
        'flag' => '',
    ]);
    $writer->addRow([
        'name' => 'KM Sumber Rejeki',
        'type' => 'repair_vessel',
        'code' => 'IMO8877665',
        'pic' => '',
        'flag' => 'Indonesia',
    ]);
    $writer->close();

    try {
        $file = new UploadedFile($path, 'locations.xlsx', null, null, true);
        $preview = $this->postJson('/api/locations/import-preview', ['file' => $file])->assertOk()->json();
        expect($preview['duplicate_count'])->toBe(1)
            ->and($preview['new_count'])->toBe(1);

        $import = $this->post('/api/locations/import', [
            'file' => $file,
            'duplicate_action' => 'update',
        ])->assertOk()->json();

        expect($import['success'])->toBe(2)
            ->and($import['updated'])->toBe(1)
            ->and($import['created'])->toBe(1);

        expect(Location::where('code', 'DERMAGA_BARAT_LAMA')->value('pic'))->toBe('Pak Budi');
        expect(Location::where('code', 'IMO8877665')->exists())->toBeTrue();
    } finally {
        @unlink($path);
        @unlink($base);
    }
});
