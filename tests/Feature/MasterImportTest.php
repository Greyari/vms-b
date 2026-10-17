<?php

use App\Models\Location;
use App\Models\Staff;
use App\Models\VehicleCard;
use App\Models\VisitorCard;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
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
        'gender' => 'male',
    ]);
    $writer->addRow([
        'staff_id' => 'STF-EX-02',
        'name' => 'New Staff',
        'department' => 'Finance',
        'gender' => 'Perempuan',
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
        expect(Staff::where('staff_id', 'STF-EX-01')->value('gender'))->toBe('M');
        expect(Staff::where('staff_id', 'STF-EX-02')->value('gender'))->toBe('F');
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

it('previews and imports cards from legacy xls file', function () {
    actingAsAdmin();

    $base = tempnam(sys_get_temp_dir(), 'vms_card_xls_');
    $path = $base.'.xls';

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['card_no', 'card_type', 'note'],
        ['VC-LEGACY-01', 'visitor', 'Legacy XLS Card'],
    ]);
    $writer = new Xls($spreadsheet);
    $writer->save($path);

    try {
        $file = new UploadedFile($path, 'cards.xls', 'application/vnd.ms-excel', null, true);
        $preview = $this->postJson('/api/cards/import-preview', ['file' => $file])->assertOk()->json();

        expect($preview['total_rows'])->toBe(1)
            ->and($preview['new_count'])->toBe(1);

        $import = $this->post('/api/cards/import', [
            'file' => $file,
            'duplicate_action' => 'update',
        ])->assertOk()->json();

        expect($import['success'])->toBe(1)
            ->and($import['created'])->toBe(1);

        expect(VisitorCard::where('card_no', 'VC-LEGACY-01')->exists())->toBeTrue();
    } finally {
        @unlink($path);
        @unlink($base);
    }
});

it('previews and imports shipyard vessel Excel format (IMONo, Description, CallSign, NationalityFlag, Type, Captain, Note)', function () {
    actingAsAdmin();

    $base = tempnam(sys_get_temp_dir(), 'vms_vessel_excel_');
    $path = $base.'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    $writer->addRow([
        'IMONo' => '9764790',
        'Description' => 'GAS WIDURI',
        'CallSign' => 'POJR',
        'NationalityFlag' => 'INDONESIA',
        'Type' => 'LPG TANKER',
        'Captain' => 'CAPT. SUTRISNO',
        'Note' => 'ALVI',
    ]);
    $writer->addRow([
        'IMONo' => '9394600',
        'Description' => 'ASL FALCON',
        'CallSign' => '9V9752',
        'NationalityFlag' => 'SINGAPORE',
        'Type' => 'TUG BOAT',
        'Captain' => 'ANDI DARMAWAN',
        'Note' => 'ALVI',
    ]);
    $writer->addRow([
        'IMONo' => '9394600',
        'Description' => 'GOLDEN FALCON',
        'CallSign' => 'S6BF6',
        'NationalityFlag' => 'SINGAPORE',
        'Type' => 'TUG BOAT',
        'Captain' => 'CAPT. TAN',
        'Note' => 'ALVI',
    ]);
    $writer->addRow([
        'IMONo' => '-',
        'Description' => 'BATAMMARINE 89',
        'CallSign' => '-',
        'NationalityFlag' => 'SINGAPORE',
        'Type' => 'BARGE',
        'Captain' => '-',
        'Note' => 'SYAHRIAL',
    ]);
    $writer->addRow([
        'IMONo' => 'MAIN OFFICE',
        'Description' => 'ASL SHIPYARD INDONESIA',
        'CallSign' => 'OFFICE',
        'NationalityFlag' => 'INDONESIA',
        'Type' => 'OFFICE',
        'Captain' => 'HEAD OFFICE',
        'Note' => 'SECURITY',
    ]);
    $writer->close();

    try {
        $file = new UploadedFile($path, 'vessels.xlsx', null, null, true);
        $preview = $this->postJson('/api/locations/import-preview', ['file' => $file])->assertOk()->json();

        expect($preview['total_rows'])->toBe(5)
            ->and($preview['new_count'])->toBe(5)
            ->and($preview['duplicate_count'])->toBe(0);

        $import = $this->post('/api/locations/import', [
            'file' => $file,
            'duplicate_action' => 'update',
        ])->assertOk()->json();

        expect($import['success'])->toBe(5)
            ->and($import['created'])->toBe(5)
            ->and($import['failed'])->toBe(0);

        $widuri = Location::where('code', '9764790')->firstOrFail();
        expect($widuri->name)->toBe('GAS WIDURI')
            ->and($widuri->type->value)->toBe('repair_vessel')
            ->and($widuri->vessel_type)->toBe('LPG TANKER')
            ->and($widuri->call_sign)->toBe('POJR')
            ->and($widuri->flag)->toBe('INDONESIA')
            ->and($widuri->captain)->toBe('CAPT. SUTRISNO')
            ->and($widuri->pic)->toBe('ALVI');

        $aslFalcon = Location::where('name', 'ASL FALCON')->firstOrFail();
        $goldenFalcon = Location::where('name', 'GOLDEN FALCON')->firstOrFail();
        expect($aslFalcon->type->value)->toBe('tug_boat')
            ->and($goldenFalcon->type->value)->toBe('tug_boat')
            ->and($aslFalcon->code)->not->toBe($goldenFalcon->code);

        $barge = Location::where('name', 'BATAMMARINE 89')->firstOrFail();
        expect($barge->type->value)->toBe('barge')
            ->and($barge->vessel_type)->toBe('BARGE')
            ->and($barge->code)->toBe('BATAMMARINE_89')
            ->and($barge->call_sign)->toBeNull()
            ->and($barge->captain)->toBeNull()
            ->and($barge->pic)->toBe('SYAHRIAL');

        $office = Location::where('name', 'ASL SHIPYARD INDONESIA')->firstOrFail();
        expect($office->type->value)->toBe('location')
            ->and($office->vessel_type)->toBe('OFFICE')
            ->and($office->code)->toBe('MAIN OFFICE')
            ->and($office->captain)->toBe('HEAD OFFICE')
            ->and($office->pic)->toBe('SECURITY');
    } finally {
        @unlink($path);
        @unlink($base);
    }
});
