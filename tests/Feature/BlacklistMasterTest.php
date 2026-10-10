<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Visitor;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\SimpleExcel\SimpleExcelWriter;
use Tests\TestCase;

class BlacklistMasterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('administrator');
    }

    public function test_can_create_blacklisted_visitor_with_validations(): void
    {
        // 1. Validation error: missing required fields (name, offences, date)
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted', [
            'name' => '',
            'offences' => '',
            'date' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'offences', 'date']);

        // 2. Success creation with standard KTP
        $validPayload = [
            'name' => 'Budi Santoso',
            'id_number' => '1234567890123456',
            'badge_code' => 'HID-9901',
            'offences' => 'Pencurian tembaga',
            'date' => '2026-10-10',
            'remark' => 'Dilarang masuk area yard',
            'company' => 'PT Sumber Rejeki',
        ];

        $createResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted', $validPayload);
        $createResponse->assertStatus(201);
        $createResponse->assertJsonPath('visitor.name', 'Budi Santoso');
        $createResponse->assertJsonPath('visitor.id_number', '1234567890123456');
        $createResponse->assertJsonPath('visitor.badge_code', 'HID-9901');
        $createResponse->assertJsonPath('visitor.offences', 'Pencurian tembaga');

        $this->assertDatabaseHas('visitors', [
            'name' => 'Budi Santoso',
            'id_number' => '1234567890123456',
            'badge_code' => 'HID-9901',
            'is_blacklisted' => true,
        ]);
    }

    public function test_can_create_blacklisted_visitor_without_ktp(): void
    {
        $payload = [
            'name' => 'John Unknown KTP',
            'id_number' => null, // Optional KTP
            'badge_code' => 'BADGE-5501',
            'offences' => 'Pelanggaran Disiplin',
            'date' => '2026-10-10',
            'remark' => 'KTP tidak ada / ditahan',
            'company' => 'PT Mitra Sejahtera',
        ];

        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted', $payload);
        $response->assertStatus(201);
        $response->assertJsonPath('visitor.name', 'John Unknown KTP');
        $this->assertNotEmpty($response->json('visitor.id_number'));
        $this->assertStringStartsWith('NOKTP', $response->json('visitor.id_number'));

        $this->assertDatabaseHas('visitors', [
            'name' => 'John Unknown KTP',
            'badge_code' => 'BADGE-5501',
            'is_blacklisted' => true,
        ]);
    }

    public function test_can_update_blacklisted_visitor(): void
    {
        $visitor = Visitor::factory()->create([
            'name' => 'John Doe',
            'id_type' => 'ktp',
            'id_number' => '9876543210987654',
            'is_blacklisted' => true,
        ]);

        $updatePayload = [
            'name' => 'Johnathan Doe',
            'id_number' => '9876543210987654',
            'badge_code' => 'HID-1122',
            'offences' => 'Melanggar SOP Safety',
            'date' => '2026-10-09',
            'remark' => 'Peringatan keras terakhir',
            'company' => 'PT Global Energi',
        ];

        $response = $this->actingAs($this->admin, 'sanctum')->putJson("/api/visitors/blacklisted/{$visitor->id}", $updatePayload);
        $response->assertStatus(200);
        $response->assertJsonPath('visitor.name', 'Johnathan Doe');
        $response->assertJsonPath('visitor.badge_code', 'HID-1122');
        $response->assertJsonPath('visitor.offences', 'Melanggar SOP Safety');

        $this->assertDatabaseHas('visitors', [
            'id' => $visitor->id,
            'name' => 'Johnathan Doe',
            'badge_code' => 'HID-1122',
            'offences' => 'Melanggar SOP Safety',
        ]);
    }

    public function test_can_list_and_search_blacklisted_visitors(): void
    {
        Visitor::factory()->create([
            'name' => 'Ali Baba',
            'id_type' => 'ktp',
            'id_number' => '1111222233334444',
            'badge_code' => 'HID-7788',
            'is_blacklisted' => true,
            'offences' => 'Merusak fasilitas',
        ]);

        Visitor::factory()->create([
            'name' => 'Active Visitor',
            'id_type' => 'ktp',
            'id_number' => '5555666677778888',
            'is_blacklisted' => false,
        ]);

        // List only blacklisted
        $listResponse = $this->actingAs($this->admin, 'sanctum')->getJson('/api/visitors/blacklisted');
        $listResponse->assertStatus(200);
        $listResponse->assertJsonCount(1, 'data');
        $listResponse->assertJsonPath('data.0.name', 'Ali Baba');

        // Search by badge_code
        $searchResponse = $this->actingAs($this->admin, 'sanctum')->getJson('/api/visitors/blacklisted?search=HID-7788');
        $searchResponse->assertStatus(200);
        $searchResponse->assertJsonCount(1, 'data');
    }

    public function test_blacklist_template_and_export(): void
    {
        Visitor::factory()->create([
            'name' => 'Bad Actor',
            'id_type' => 'ktp',
            'id_number' => '1234567890123456',
            'badge_code' => 'HID-001',
            'is_blacklisted' => true,
            'offences' => 'Pencurian',
            'blacklisted_at' => '2026-10-10',
            'remark' => 'Awas bahaya',
            'company' => 'PT Kejahatan',
        ]);

        $templateResponse = $this->actingAs($this->admin, 'sanctum')->get('/api/visitors/blacklisted/template');
        $templateResponse->assertOk();
        $this->assertStringContainsString('.xlsx', $templateResponse->headers->get('content-disposition'));

        $exportResponse = $this->actingAs($this->admin, 'sanctum')->get('/api/visitors/blacklisted/export');
        $exportResponse->assertOk();
        $this->assertStringContainsString('blacklisted_visitors.xlsx', $exportResponse->headers->get('content-disposition'));
    }

    public function test_blacklist_import_preview_and_execution_with_duplicate(): void
    {
        Visitor::factory()->create([
            'name' => 'Old Offender',
            'id_type' => 'ktp',
            'id_number' => '3201010101010001',
            'badge_code' => 'HID-100',
            'is_blacklisted' => true,
            'offences' => 'Pelanggaran Lama',
            'blacklisted_at' => '2026-01-01',
            'remark' => 'Lama',
        ]);

        $base = tempnam(sys_get_temp_dir(), 'vms_bl_');
        $path = $base.'.xlsx';
        $writer = SimpleExcelWriter::create($path);
        // Duplicate row (matching KTP)
        $writer->addRow([
            'NAME OF OFFENDER' => 'Old Offender Updated',
            'Badge / H.I.D code' => 'HID-100',
            'KTP NO' => '3201010101010001',
            'OFFENCES' => 'Pelanggaran Diperbarui',
            'DATE' => '2026-10-10',
            'REMARK' => 'Catatan Baru',
            'COMPANY NAME' => 'PT Baru',
        ]);
        // Brand new row
        $writer->addRow([
            'NAME OF OFFENDER' => 'New Offender',
            'Badge / H.I.D code' => 'HID-200',
            'KTP NO' => '3201010101010002',
            'OFFENCES' => 'Pelanggaran Baru',
            'DATE' => '2026-10-10',
            'REMARK' => 'Baru',
            'COMPANY NAME' => 'PT Lain',
        ]);
        $writer->close();

        try {
            $file = new UploadedFile($path, 'blacklist.xlsx', null, null, true);

            // Preview
            $previewResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted/import-preview', [
                'file' => $file,
            ]);
            $previewResponse->assertOk();
            $previewData = $previewResponse->json();
            $this->assertEquals(2, $previewData['total_rows']);
            $this->assertEquals(1, $previewData['duplicate_count']);
            $this->assertEquals(1, $previewData['new_count']);

            // Execute Import with 'update'
            $importFile = new UploadedFile($path, 'blacklist.xlsx', null, null, true);
            $importResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted/import', [
                'file' => $importFile,
                'duplicate_action' => 'update',
            ]);
            $importResponse->assertOk();
            $importData = $importResponse->json();
            $this->assertEquals(2, $importData['total']);
            $this->assertEquals(1, $importData['updated']);
            $this->assertEquals(1, $importData['created']);

            $this->assertDatabaseHas('visitors', [
                'id_number' => '3201010101010001',
                'offences' => 'Pelanggaran Diperbarui',
                'remark' => 'Catatan Baru',
            ]);
            $this->assertDatabaseHas('visitors', [
                'id_number' => '3201010101010002',
                'name' => 'New Offender',
                'is_blacklisted' => true,
            ]);
        } finally {
            @unlink($path);
            @unlink($base);
        }
    }

    public function test_blacklist_import_with_legacy_xls_file(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'vms_xls_');
        $path = $base.'.xls';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['NAME OF OFFENDER', 'Badge / H.I.D code', 'KTP NO', 'OFFENCES', 'DATE', 'REMARK', 'COMPANY NAME'],
            ['Legacy XLS Offender', 'HID-XLS-01', '3201010101010999', 'Melanggar SOP', '2026-10-10', 'Catatan XLS', 'PT XLS Mandiri'],
        ]);
        $writer = new Xls($spreadsheet);
        $writer->save($path);

        try {
            $file = new UploadedFile($path, 'blacklist.xls', 'application/vnd.ms-excel', null, true);

            // 1. Preview .xls file
            $previewResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted/import-preview', [
                'file' => $file,
            ]);
            $previewResponse->assertOk();
            $previewData = $previewResponse->json();
            $this->assertEquals(1, $previewData['total_rows']);
            $this->assertEquals(0, $previewData['duplicate_count']);
            $this->assertEquals(1, $previewData['new_count']);

            // 2. Import .xls file
            $importFile = new UploadedFile($path, 'blacklist.xls', 'application/vnd.ms-excel', null, true);
            $importResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted/import', [
                'file' => $importFile,
                'duplicate_action' => 'update',
            ]);
            $importResponse->assertOk();
            $importData = $importResponse->json();
            $this->assertEquals(1, $importData['total']);
            $this->assertEquals(1, $importData['created']);

            $this->assertDatabaseHas('visitors', [
                'id_number' => '3201010101010999',
                'name' => 'Legacy XLS Offender',
                'is_blacklisted' => true,
                'badge_code' => 'HID-XLS-01',
                'offences' => 'Melanggar SOP',
            ]);
        } finally {
            @unlink($path);
            @unlink($base);
        }
    }

    public function test_import_handles_security_office_diverse_formats(): void
    {
        $base = tempnam(sys_get_temp_dir(), 'vms_security_');
        $path = $base.'.xlsx';

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['NO', 'NAME OF OFFENDER', 'Badge / H.I.D  code', 'KTP NO', 'OFFENCES', 'DATE', 'REMARK', 'COMPANY NAME'],
            ['1', 'Andi Serial Date', 'HID-001', '2171032006820013', 'Kasus A', '40404.0', 'Serial test', 'PT Alpha'],
            ['2', 'Budi Passport', 'HID-002', 'A944790 passport', 'Kasus B', '16/8/08', 'Passport test', 'PT Beta'],
            ['3', 'Candra KTP Text', '14081', '7304071903890001 KTP', 'Kasus C', '24.10.08', 'Suffix test', 'PT Gamma'],
            ['4', 'Dedi No KTP', '50064', 'Not available', 'Kasus D', "11 Dec '12", 'No KTP test', 'PT Delta'],
            ['5', 'Eko SIM', 'HID-005', '840416380046 SIM', 'Kasus E', '22/01/2024', 'SIM test', 'PT Epsilon'],
            ['', '', '', '', '', '', '', ''], // empty row should be skipped
        ]);
        $writer = new Xlsx($spreadsheet);
        $writer->save($path);

        try {
            $file = new UploadedFile($path, 'security_office.xlsx', null, null, true);

            // Preview
            $previewResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted/import-preview', [
                'file' => $file,
            ]);
            $previewResponse->assertOk();
            $previewData = $previewResponse->json();
            $this->assertEquals(5, $previewData['total_rows']);
            $this->assertEquals(0, $previewData['duplicate_count']);
            $this->assertEquals(5, $previewData['new_count']);

            // Execute Import
            $importFile = new UploadedFile($path, 'security_office.xlsx', null, null, true);
            $importResponse = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted/import', [
                'file' => $importFile,
                'duplicate_action' => 'update',
            ]);
            $importResponse->assertOk();
            $importData = $importResponse->json();
            $this->assertEquals(5, $importData['total']);
            $this->assertEquals(5, $importData['created']);
            $this->assertEquals(0, $importData['failed']);

            // Verify Row 1: Excel Serial Date parsed correctly
            $this->assertDatabaseHas('visitors', [
                'name' => 'Andi Serial Date',
                'id_number' => '2171032006820013',
                'is_blacklisted' => true,
            ]);
            $v1 = Visitor::where('name', 'Andi Serial Date')->first();
            $this->assertNotNull($v1->blacklisted_at);

            // Verify Row 2: Passport recognized
            $this->assertDatabaseHas('visitors', [
                'name' => 'Budi Passport',
                'id_type' => 'passport',
                'id_number' => 'A944790',
            ]);

            // Verify Row 3: Suffix cleaned
            $this->assertDatabaseHas('visitors', [
                'name' => 'Candra KTP Text',
                'id_type' => 'ktp',
                'id_number' => '7304071903890001',
            ]);

            // Verify Row 4: Synthetic NOKTP assigned
            $v4 = Visitor::where('name', 'Dedi No KTP')->first();
            $this->assertNotNull($v4);
            $this->assertStringStartsWith('NOKTP', $v4->id_number);

            // Verify Row 5: SIM recognized
            $this->assertDatabaseHas('visitors', [
                'name' => 'Eko SIM',
                'id_type' => 'sim',
                'id_number' => '840416380046',
            ]);
        } finally {
            @unlink($path);
            @unlink($base);
        }
    }

    public function test_import_rows_without_ktp_do_not_collide_or_overwrite_real_ktp(): void
    {
        // Already blacklisted with a real KTP; the file only knows the badge.
        Visitor::factory()->create([
            'name' => 'Rudi Existing',
            'id_type' => 'ktp',
            'id_number' => '2171032511880003',
            'badge_code' => '12328',
            'is_blacklisted' => true,
        ]);

        $base = tempnam(sys_get_temp_dir(), 'vms_noktp_');
        $path = $base.'.xlsx';

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['NO', 'NAME OF OFFENDER', 'Badge / H.I.D code', 'KTP NO', 'OFFENCES', 'DATE', 'REMARK', 'COMPANY NAME'],
            ['1', 'Rudi Existing', '12328', 'Badge No 12328', 'Kasus A', '2024-01-01', '', 'PT A'],
            ['2', 'Rudi Existing', '12328', 'Badge No 12328', 'Kasus A lagi', '2024-02-01', '', 'PT A'],
            ['3', 'Tono Temp', '', 'Temp Pass', 'Kasus B', '2024-03-01', '', 'PT B'],
            ['4', 'Sari Temp', '', 'Temp. Pass', 'Kasus C', '2024-04-01', '', 'PT C'],
        ]);
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        try {
            $file = new \Illuminate\Http\UploadedFile($path, 'noktp.xlsx', null, null, true);
            $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/visitors/blacklisted/import', [
                'file' => $file,
                'duplicate_action' => 'replace',
            ]);

            $response->assertOk();
            $this->assertEquals(0, $response->json('failed'), json_encode($response->json('failed_details')));

            // Real KTP kept, not replaced by a NOKTP placeholder.
            $this->assertDatabaseHas('visitors', ['name' => 'Rudi Existing', 'id_number' => '2171032511880003']);

            // "Temp Pass" is not an identity: two different people stay two records.
            $tono = Visitor::where('name', 'Tono Temp')->firstOrFail();
            $sari = Visitor::where('name', 'Sari Temp')->firstOrFail();
            $this->assertNotEquals($tono->id, $sari->id);
            $this->assertStringStartsWith('NOKTP', $tono->id_number);
            $this->assertStringNotContainsString('-', $tono->id_number);
        } finally {
            @unlink($path);
            @unlink($base);
        }
    }
}
