<?php

use App\Models\Visitor;
use Illuminate\Http\UploadedFile;
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
