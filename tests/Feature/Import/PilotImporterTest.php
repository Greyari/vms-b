<?php

namespace Tests\Feature\Import;

use App\Models\Staff;
use App\Models\User;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use App\Services\Import\ImportReport;
use App\Services\Import\MappingManager;
use App\Services\Import\PilotImporter;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PilotImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->tempDir = storage_path('framework/testing/pilot_import_'.uniqid());
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_imports_pilot_data_merges_visitor_and_preserves_ticket_number(): void
    {
        // 1. Existing VMS-old visitor in database
        $existingVisitor = Visitor::forceCreate([
            'visitor_no' => 500,
            'id_type' => 'ktp',
            'id_number' => '1111222233334444',
            'name' => 'Existing VMS Old Guy',
            'company' => 'PT ASL Old',
            'phone' => null, // empty, to be merged
            'is_blacklisted' => false,
            'id_lifetime' => true,
        ]);

        // 2. Existing user
        User::factory()->create([
            'email' => 'existing@pilot.test',
        ]);

        // 3. Create synthetic CSV files
        // staffs.csv
        $staffCsv = "id,staff_id,name,department\n".
                    "1,ST-001,Ahmad Pilot,IT\n";
        File::put($this->tempDir.'/staffs.csv', $staffCsv);

        // cards.csv
        $cardCsv = "id,card_number,status\n".
                   "1,PC-001,available\n";
        File::put($this->tempDir.'/cards.csv', $cardCsv);

        // visitors.csv: 1 existing, 1 new
        $visitorCsv = "id,name,ktp_number,company,phone\n".
                      "10,Existing VMS Old Guy,1111222233334444,PT ASL Old,0811223344\n".
                      "11,Brand New Pilot Guy,9999888877776666,PT New Pilot,0899887766\n";
        File::put($this->tempDir.'/visitors.csv', $visitorCsv);

        // visits.csv: night shift visit with rollover (22:00 -> 02:00)
        $visitCsv = "id,ticket_number,visitor_id,staff_id,date,time_in,time_out,status\n".
                    "101,PILOT-20260501-001,10,1,2026-05-01,22:00:00,02:00:00,checked_out\n";
        File::put($this->tempDir.'/visits.csv', $visitCsv);

        // users.csv
        $userCsv = "id,name,email,password,roles\n".
                   '1,New Admin,newadmin@pilot.test,'.bcrypt('secret').",administrator\n".
                   '2,Existing User,existing@pilot.test,'.bcrypt('secret').",security\n";
        File::put($this->tempDir.'/users.csv', $userCsv);

        // manifest.json
        $manifest = [
            'source' => 'pilot',
            'exported_at' => now()->toIso8601String(),
            'tables' => [
                'staffs' => ['file' => 'staffs.csv', 'row_count' => 1],
                'cards' => ['file' => 'cards.csv', 'row_count' => 1],
                'visitors' => ['file' => 'visitors.csv', 'row_count' => 2],
                'visits' => ['file' => 'visits.csv', 'row_count' => 1],
                'users' => ['file' => 'users.csv', 'row_count' => 2],
            ],
        ];
        File::put($this->tempDir.'/manifest.json', json_encode($manifest));

        // 4. Run PilotImporter
        $report = new ImportReport;
        $mappingManager = new MappingManager;
        $importer = new PilotImporter($mappingManager, $report);

        $importer->import($this->tempDir);

        // 5. Assert Staff
        $staff = Staff::where('staff_id', 'ST-001')->first();
        $this->assertNotNull($staff);
        $this->assertSame('Ahmad Pilot', $staff->name);

        // 6. Assert Card
        $card = VisitorCard::where('card_no', 'PC-001')->first();
        $this->assertNotNull($card);
        $this->assertSame('available', $card->status);

        // 7. Assert Visitor Merging
        $existingVisitor->refresh();
        $this->assertSame(500, $existingVisitor->visitor_no); // visitor_no unchanged!
        $this->assertSame('0811223344', $existingVisitor->phone); // merged phone

        // Assert New Visitor
        $newVisitor = Visitor::where('id_number', '9999888877776666')->first();
        $this->assertNotNull($newVisitor);
        $this->assertGreaterThanOrEqual(1000000, $newVisitor->visitor_no); // visitor_no >= 1.000.000

        // 8. Assert Visit & Rollover
        $visit = Visit::where('ticket_number', 'PILOT-20260501-001')->first();
        $this->assertNotNull($visit);
        $this->assertSame($existingVisitor->id, $visit->visitor_id);
        $this->assertSame($staff->id, $visit->staff_id);
        $this->assertSame('2026-05-01', $visit->date);
        $this->assertSame('22:00:00', $visit->time_in);
        $this->assertSame('02:00:00', $visit->time_out);
        $this->assertSame('2026-05-02 02:00:00', $visit->checked_out_at->format('Y-m-d H:i:s')); // Rolled over!

        // 9. Assert Users
        $newAdmin = User::where('email', 'newadmin@pilot.test')->first();
        $this->assertNotNull($newAdmin);
        $this->assertTrue($newAdmin->hasRole('administrator'));

        // 10. Assert Report Stats
        $stats = $report->toArray();
        $this->assertSame(2, $stats['entities']['visitors']['read']);
        $this->assertSame(1, $stats['entities']['visitors']['inserted']);
        $this->assertSame(1, $stats['entities']['visitors']['updated']);
        $this->assertSame(1, $stats['entities']['users']['inserted']);
        $this->assertSame(1, $stats['entities']['users']['skipped']['total']);
    }
}
