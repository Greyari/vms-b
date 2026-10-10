<?php

use App\Models\Visitor;
use App\Models\VisitorCard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Spatie\SimpleExcel\SimpleExcelWriter;

beforeEach(function () {
    actingAsAdmin();
});

/**
 * Write a real .xlsx to a temp path and wrap it in a test-mode UploadedFile,
 * mirroring exactly how a browser upload arrives at the controller.
 *
 * @param  array<int, array<string, mixed>>  $rows
 */
function makeXlsxUpload(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'vmsimp_').'.xlsx';
    $writer = SimpleExcelWriter::create($path);
    foreach ($rows as $row) {
        $writer->addRow($row);
    }
    $writer->close();

    return new UploadedFile($path, 'import.xlsx', null, null, true);
}

function makeXlsUpload(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'vmsimpxls_').'.xls';
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();

    if (! empty($rows)) {
        $header = array_keys($rows[0]);
        $data = [$header];
        foreach ($rows as $r) {
            $data[] = array_values($r);
        }
        $sheet->fromArray($data);
    }

    $writer = new Xls($spreadsheet);
    $writer->save($path);

    return new UploadedFile($path, 'import.xls', 'application/vnd.ms-excel', null, true);
}

it('imports a valid visitor spreadsheet and creates rows', function () {
    $file = makeXlsxUpload([
        ['ID Type' => 'ktp', 'ID Number' => '6210000000000001', 'Name' => 'Import One', 'Company' => 'PT A'],
        ['ID Type' => 'passport', 'ID Number' => 'A1234567', 'Name' => 'Import Two', 'Company' => 'PT B'],
    ]);

    $response = $this->post('/api/visitors/import', ['file' => $file]);

    $response->assertOk();
    expect($response->json('created'))->toBe(2);
    expect(Visitor::where('id_number', '6210000000000001')->exists())->toBeTrue();
    expect(Visitor::where('id_number', 'A1234567')->where('id_type', 'passport')->exists())->toBeTrue();
});

it('imports a valid visitor legacy xls spreadsheet and creates rows', function () {
    $file = makeXlsUpload([
        ['ID Type' => 'ktp', 'ID Number' => '6210000000000008', 'Name' => 'Import XLS One', 'Company' => 'PT XLS A'],
        ['ID Type' => 'passport', 'ID Number' => 'B9876543', 'Name' => 'Import XLS Two', 'Company' => 'PT XLS B'],
    ]);

    $response = $this->post('/api/visitors/import', ['file' => $file]);

    $response->assertOk();
    expect($response->json('created'))->toBe(2);
    expect(Visitor::where('id_number', '6210000000000008')->exists())->toBeTrue();
    expect(Visitor::where('id_number', 'B9876543')->where('id_type', 'passport')->exists())->toBeTrue();
});

it('skips a numeric (integer) ID cell — IDs must be text-formatted (M5)', function () {
    // A numeric-formatted cell may already be rounded by the spreadsheet, so the
    // importer refuses it rather than store a possibly-corrupted id_number.
    $file = makeXlsxUpload([
        ['ID Type' => 'ktp', 'ID Number' => 1234567890123456, 'Name' => 'Integer Id', 'Company' => ''],
    ]);

    $response = $this->post('/api/visitors/import', ['file' => $file]);
    $response->assertOk();

    expect(Visitor::where('name', 'Integer Id')->exists())->toBeFalse();
    expect($response->json('skipped'))->toBe(1);
});

it('skips a numeric (float) ID cell instead of storing a corrupted value (M5)', function () {
    // A 16-digit KTP typed as a number is rounded before we ever see it; the
    // importer must SKIP such rows, never silently store a wrong id_number
    // (which on a blacklist import would admit a blacklisted person). Text
    // (string) ID cells are the supported input.
    $file = makeXlsxUpload([
        ['ID Type' => 'ktp', 'ID Number' => 1234567890123456.0, 'Name' => 'Float Id', 'Company' => ''],
    ]);

    $response = $this->post('/api/visitors/import', ['file' => $file]);
    $response->assertOk();

    expect(Visitor::where('name', 'Float Id')->exists())->toBeFalse();
    expect($response->json('skipped'))->toBe(1);
});

it('skips a row with an invalid id_type instead of defaulting to ktp', function () {
    $file = makeXlsxUpload([
        ['ID Type' => 'drivers-license', 'ID Number' => '6210000000000099', 'Name' => 'Bad Type', 'Company' => ''],
    ]);

    $response = $this->post('/api/visitors/import', ['file' => $file]);

    $response->assertOk();
    expect($response->json('skipped'))->toBe(1);
    expect($response->json('created'))->toBe(0);
    expect(Visitor::where('id_number', '6210000000000099')->exists())->toBeFalse();
});

it('skips a row that is missing the Name', function () {
    $file = makeXlsxUpload([
        ['ID Type' => 'ktp', 'ID Number' => '6210000000000100', 'Name' => '', 'Company' => 'PT C'],
    ]);

    $response = $this->post('/api/visitors/import', ['file' => $file]);

    $response->assertOk();
    expect($response->json('skipped'))->toBe(1);
    expect(Visitor::where('id_number', '6210000000000100')->exists())->toBeFalse();
});

it('aborts an over-cap import with 422', function () {
    config()->set('vms.import_max_rows', 3);

    $rows = [];
    for ($i = 1; $i <= 4; $i++) {
        $rows[] = ['ID Type' => 'ktp', 'ID Number' => '710000000000000'.$i, 'Name' => "Row {$i}", 'Company' => ''];
    }
    $file = makeXlsxUpload($rows);

    $this->post('/api/visitors/import', ['file' => $file])->assertStatus(422);
});

it('imports a blacklist file, flagging visitors and creating unknown ones', function () {
    $file = makeXlsxUpload([
        ['ID Type' => 'ktp', 'ID Number' => '8210000000000001', 'Name' => 'New Banned', 'Company' => '', 'Reason' => 'Fraud'],
    ]);

    $response = $this->post('/api/visitors/blacklisted/import', ['file' => $file]);

    $response->assertOk();
    $visitor = Visitor::where('id_number', '8210000000000001')->first();
    expect($visitor)->not->toBeNull();
    expect($visitor->is_blacklisted)->toBeTrue();
    expect($visitor->blacklist_reason)->toBe('Fraud');
});

it('normalizes id numbers from a spreadsheet import', function () {
    $file = makeXlsxUpload([
        ['ID Type' => 'ktp', 'ID Number' => '6210-0000 0000-0001', 'Name' => 'Dashed Import', 'Company' => ''],
    ]);

    $this->post('/api/visitors/import', ['file' => $file])->assertOk();

    expect(Visitor::where('id_number', '6210000000000001')->exists())->toBeTrue();
});

/** @return array<string, mixed> */
function decoyVisitorRow(Visitor $visitor): array
{
    return [
        'id' => (string) Str::uuid7(),
        'id_type' => 'ktp',
        'id_number' => 'RACEDECOY'.$visitor->visitor_no,
        'name' => 'Race Decoy',
        'visitor_no' => $visitor->visitor_no,
        'is_blacklisted' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('retries a visitor import that loses a visitor_no race once, without double counting', function () {
    $triggered = false;
    raceVisitorNoOnCreating(function (Visitor $visitor) use (&$triggered): void {
        if ($triggered) {
            return;
        }
        $triggered = true;
        DB::table('visitors')->insert(decoyVisitorRow($visitor));
    });

    try {
        $file = makeXlsxUpload([
            ['ID Type' => 'ktp', 'ID Number' => '6210000000000301', 'Name' => 'Race Import One', 'Company' => 'PT A'],
            ['ID Type' => 'ktp', 'ID Number' => '6210000000000302', 'Name' => 'Race Import Two', 'Company' => 'PT B'],
        ]);

        $response = $this->post('/api/visitors/import', ['file' => $file]);

        $response->assertOk();
        expect($response->json('created'))->toBe(2);
        expect($response->json('updated'))->toBe(0);
        expect($response->json('skipped'))->toBe(0);
        expect(Visitor::where('id_number', '6210000000000301')->exists())->toBeTrue();
        expect(Visitor::where('id_number', '6210000000000302')->exists())->toBeTrue();
        // The decoy only ever lived inside the rolled-back first attempt.
        expect(Visitor::where('name', 'Race Decoy')->exists())->toBeFalse();
    } finally {
        Visitor::flushEventListeners();
        Visitor::clearBootedModels();
    }
});

it('retries a blacklist import that loses a visitor_no race once, without double counting', function () {
    $triggered = false;
    raceVisitorNoOnCreating(function (Visitor $visitor) use (&$triggered): void {
        if ($triggered) {
            return;
        }
        $triggered = true;
        DB::table('visitors')->insert(decoyVisitorRow($visitor));
    });

    try {
        $file = makeXlsxUpload([
            ['ID Type' => 'ktp', 'ID Number' => '8210000000000301', 'Name' => 'Race Blacklist One', 'Company' => '', 'Reason' => 'Fraud'],
            ['ID Type' => 'ktp', 'ID Number' => '8210000000000302', 'Name' => 'Race Blacklist Two', 'Company' => '', 'Reason' => 'Theft'],
        ]);

        $response = $this->post('/api/visitors/blacklisted/import', ['file' => $file]);

        $response->assertOk();
        expect($response->json('created'))->toBe(2);
        expect($response->json('updated'))->toBe(0);
        expect($response->json('skipped'))->toBe(0);

        $one = Visitor::where('id_number', '8210000000000301')->first();
        $two = Visitor::where('id_number', '8210000000000302')->first();
        expect($one)->not->toBeNull();
        expect($one->is_blacklisted)->toBeTrue();
        expect($two)->not->toBeNull();
        expect($two->is_blacklisted)->toBeTrue();
        expect(Visitor::where('name', 'Race Decoy')->exists())->toBeFalse();
    } finally {
        Visitor::flushEventListeners();
        Visitor::clearBootedModels();
    }
});

it('gives up after 3 attempts with 500 and persists nothing from the file', function () {
    Exceptions::fake();
    // Unconditional: every attempt (up to the retry cap) loses the race.
    raceVisitorNoOnCreating(function (Visitor $visitor): void {
        DB::table('visitors')->insert(decoyVisitorRow($visitor));
    });

    try {
        $file = makeXlsxUpload([
            ['ID Type' => 'ktp', 'ID Number' => '6210000000000401', 'Name' => 'Never Persisted', 'Company' => ''],
        ]);

        $response = $this->post('/api/visitors/import', ['file' => $file]);

        $response->assertStatus(500);
        $response->assertExactJson([
            'message' => 'Import failed',
            'error' => 'Could not allocate a unique visitor number, please retry.',
        ]);

        expect(Visitor::where('id_number', '6210000000000401')->exists())->toBeFalse();
        expect(Visitor::count())->toBe(0);
        Exceptions::assertReported(UniqueConstraintViolationException::class);
    } finally {
        Visitor::flushEventListeners();
        Visitor::clearBootedModels();
    }
});

it('does not retry an import that fails on an unrelated unique index and reports it', function () {
    Exceptions::fake();
    VisitorCard::factory()->create(['card_no' => 'IMPORT-CLASH']);
    $attempts = 0;

    raceVisitorNoOnCreating(function () use (&$attempts): void {
        $attempts++;
        DB::table('visitor_cards')->insert([
            'id' => (string) Str::uuid7(),
            'card_no' => 'IMPORT-CLASH',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    try {
        $file = makeXlsxUpload([
            ['ID Type' => 'ktp', 'ID Number' => '6210000000000402', 'Name' => 'Never Persisted', 'Company' => ''],
        ]);

        $this->post('/api/visitors/import', ['file' => $file])
            ->assertStatus(500)
            ->assertExactJson([
                'message' => 'Import failed',
                'error' => 'The file conflicts with an existing record.',
            ]);

        expect($attempts)->toBe(1);
        expect(Visitor::count())->toBe(0);
        Exceptions::assertReported(UniqueConstraintViolationException::class);
    } finally {
        Visitor::flushEventListeners();
        Visitor::clearBootedModels();
    }
});
