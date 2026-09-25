<?php

namespace Tests\Feature\Import;

use App\Models\Location;
use App\Models\Nationality;
use App\Models\Staff;
use App\Models\VesselArrival;
use App\Models\Visit;
use App\Models\Visitor;
use App\Models\VisitorCard;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class VmsOldImportEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->tempDir = storage_path('framework/testing/vms_e2e_'.uniqid());
        File::makeDirectory($this->tempDir, 0755, true);

        $this->setupSyntheticVmsOldDataset();
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function setupSyntheticVmsOldDataset(): void
    {
        File::put($this->tempDir.'/Nationality.csv',
            "NationalityCode,Description,Note\n".
            "IDN,INDONESIA,\n".
            "SGP,SINGAPORE,\n"
        );

        File::put($this->tempDir.'/ESSVesselMaster.csv',
            "IMONo,Description,CallSign,Sign,NationalityFlag,Port,Type,Captain,LocalCompanyAgent,LocalCompanyAgentName,LocalCompanyAgentPhoneNo,LocalCompanyAgentLocation,ForeignCompanyAgent,ForeignCompanyAgentName,ForeignCompanyAgentPhoneNo,ForeignCompanyAgentLocation,Note,isVessel,LastModifiedBy,LastModifiedDate\n".
            "9123456,MV OCEANIC,ABCD,SG,SINGAPORE,SINGAPORE,CONTAINER,Capt. Jack,ASL Local,Budi,0812,Batam,For Co,Smith,+65,SG,Notes,0,admin,2026-01-01\n".
            ",JETTY 1,,,,,,,,,,,,,,1,admin,2026-01-01\n"
        );

        File::put($this->tempDir.'/ESSStaffToVisit.csv',
            "StaffID,StaffFullName,StaffGender,StaffBirthDate,StaffCompany,StaffAddress,StaffOccupation,VisitorStatus,Note,LastModifiedBy,LastModifiedDate\n".
            "10,John Host,1,1980-01-01,PT ASL,Batam,Manager,1,HOST-01,admin,2026-01-01\n"
        );

        File::put($this->tempDir.'/ESSVisitorCardMaster.csv',
            "CardNo,AbsenteeismNo,ActiveStatus,Note,LastModifiedBy,LastModifiedDate\n".
            "VC-OPEN,,1,VISITOR PASS,admin,2026-01-01\n".
            "VC-CLOSED,,1,VISITOR PASS,admin,2026-01-01\n"
        );

        File::put($this->tempDir.'/ESSVisitorMaster.csv',
            "VisitorID,VisitorIDType,VisitorIDNumber,VisitorFullName,VisitorGender,VisitorBirthDate,VisitorCompany,VisitorAddress,VisitorOccupation,VisitorType,VisitorStatus,Note,LastModifiedBy,LastModifiedDate,IMONo,ExpiryDate,IssueDate,SeamanBookExpiryDate,PhoneNo,PlaceofBirth,Certificate,CertificateNo,SeamanBookNo\n".
            "501,K,3201000000000001,Tamu Satu,1,1990-01-01,PT Tamu,INDONESIA,Tech,1,,note,admin,2026-01-01,,1900-01-01,,,,,,\n".
            "502,P,P998877,Kru Kapal,M,1985-05-05,Shipping Co,SINGAPORE,Sailor,2,,note,admin,2026-01-01,9123456,2030-01-01,,,Master,C-01,SB-01\n"
        );

        File::put($this->tempDir.'/ESSVisitorRegistration.csv',
            "VisitorID,CardNo,TransactionDate,SequenceNo,VehicleNo,EmployeeToVisit,VisitLocation,VisitPurpose,CheckInTime,CheckOutTime,Note,LastModifiedBy,LastModifiedDate,VisitorRegistrationNo,VisitorCompany,StaffID\n".
            "501,VC-OPEN,2026-05-01 08:00:00,1,JETTY_1,John Host,JETTY 1,Survey,8.0,0,BP 1111 AA,admin,2026-05-01 08:00:00,VR-001,PT Tamu,10\n".
            "502,VC-CLOSED,2026-05-02 08:00:00,2,9123456,John Host,MV OCEANIC,Boarding,8.0,17.0,BP 2222 BB,admin,2026-05-02 17:00:15,VR-002,Shipping Co,10\n"
        );

        File::put($this->tempDir.'/ESSVesselTransaction.csv',
            "SequenceNo,IMONo,AssignmentSignIn,AssignmentSignOut,PortSignIn,PortSignOut,Captain,LocalCompanyAgent,LocalCompanyAgentName,LocalCompanyAgentPhoneNo,LocalCompanyAgentLocation,ForeignCompanyAgent,ForeignCompanyAgentName,ForeignCompanyAgentPhoneNo,ForeignCompanyAgentLocation,Note,LastModifiedBy,LastModifiedDate\n".
            "1,9123456,2026-05-01,2026-05-10,Batam,Singapore,Capt. Jack,,,,,,,,,,,admin,2026-05-01\n"
        );

        File::put($this->tempDir.'/ESSCrewTransaction.csv',
            "SequenceNo,IMONo,VisitorID,Note,LastModifiedBy,LastModifiedDate,TransactionID\n".
            "1,9123456,502,,admin,2026-05-01,1\n"
        );

        $manifest = [
            'source' => 'vms-old',
            'exported_at' => now()->toIso8601String(),
            'tables' => [
                'Nationality' => ['row_count' => 2],
                'ESSVesselMaster' => ['row_count' => 2],
                'ESSStaffToVisit' => ['row_count' => 1],
                'ESSVisitorCardMaster' => ['row_count' => 2],
                'ESSVisitorMaster' => ['row_count' => 2],
                'ESSVisitorRegistration' => ['row_count' => 2],
                'ESSVesselTransaction' => ['row_count' => 1],
                'ESSCrewTransaction' => ['row_count' => 1],
            ],
        ];
        File::put($this->tempDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
    }

    public function test_full_idempotency_on_consecutive_runs(): void
    {
        // Run 1: Initial Import
        $this->artisan('vms:import', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->assertExitCode(0);

        // Record initial counts
        $nationalitiesCount1 = Nationality::count();
        $locationsCount1 = Location::count();
        $staffCount1 = Staff::count();
        $cardsCount1 = VisitorCard::count();
        $visitorsCount1 = Visitor::count();
        $visitsCount1 = Visit::count();
        $vesselArrivalsCount1 = VesselArrival::count();
        $crewCount1 = DB::table('vessel_arrival_crew')->count();
        $pivotCardCount1 = DB::table('visit_visitor_card')->count();
        $mappingsCount1 = DB::table('import_mappings')->count();

        $this->assertSame(2, $nationalitiesCount1);
        $this->assertSame(2, $locationsCount1);
        $this->assertSame(1, $staffCount1);
        $this->assertSame(2, $cardsCount1);
        $this->assertSame(2, $visitorsCount1);
        $this->assertSame(2, $visitsCount1);
        $this->assertSame(1, $vesselArrivalsCount1);
        $this->assertSame(1, $crewCount1);

        // Verify with import-verify command
        $this->artisan('vms:import-verify', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->expectsOutputToContain('All 7 integrity checks PASSED.')
            ->assertExitCode(0);

        // Run 2: Exact same dataset re-imported
        $this->artisan('vms:import', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->assertExitCode(0);

        // Verify counts remain identical (0 new inserts, completely idempotent)
        $this->assertSame($nationalitiesCount1, Nationality::count(), 'Nationality count must not change on rerun');
        $this->assertSame($locationsCount1, Location::count(), 'Location count must not change on rerun');
        $this->assertSame($staffCount1, Staff::count(), 'Staff count must not change on rerun');
        $this->assertSame($cardsCount1, VisitorCard::count(), 'Card count must not change on rerun');
        $this->assertSame($visitorsCount1, Visitor::count(), 'Visitor count must not change on rerun');
        $this->assertSame($visitsCount1, Visit::count(), 'Visit count must not change on rerun');
        $this->assertSame($vesselArrivalsCount1, VesselArrival::count(), 'VesselArrival count must not change on rerun');
        $this->assertSame($crewCount1, DB::table('vessel_arrival_crew')->count(), 'Crew count must not change on rerun');
        $this->assertSame($pivotCardCount1, DB::table('visit_visitor_card')->count(), 'Pivot card count must not change on rerun');
        $this->assertSame($mappingsCount1, DB::table('import_mappings')->count(), 'Mappings count must not change on rerun');

        // Verification command still passes cleanly after rerun
        $this->artisan('vms:import-verify', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->expectsOutputToContain('All 7 integrity checks PASSED.')
            ->assertExitCode(0);
    }

    public function test_delta_import_updates_open_visit_and_syncs_card_status(): void
    {
        // 1. Initial import: VR-001 is open (CheckOutTime = 0), VC-OPEN is in_use
        $this->artisan('vms:import', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->assertExitCode(0);

        $cardOpen = VisitorCard::where('card_no', 'VC-OPEN')->first();
        $this->assertNotNull($cardOpen);
        $this->assertSame('in_use', $cardOpen->status);

        $visit1 = Visit::where('legacy_registration_no', 'VR-001')->first();
        $this->assertNotNull($visit1);
        $this->assertNull($visit1->checked_out_at);

        // 2. Delta dataset:
        // - VR-001 is now checked out (CheckOutTime = 17.0)
        // - VR-003 is a new open visit with VC-OPEN
        File::put($this->tempDir.'/ESSVisitorRegistration.csv',
            "VisitorID,CardNo,TransactionDate,SequenceNo,VehicleNo,EmployeeToVisit,VisitLocation,VisitPurpose,CheckInTime,CheckOutTime,Note,LastModifiedBy,LastModifiedDate,VisitorRegistrationNo,VisitorCompany,StaffID\n".
            "501,VC-OPEN,2026-05-01 08:00:00,1,JETTY_1,John Host,JETTY 1,Survey,8.0,17.0,BP 1111 AA,admin,2026-05-01 17:00:00,VR-001,PT Tamu,10\n".
            "502,VC-CLOSED,2026-05-02 08:00:00,2,9123456,John Host,MV OCEANIC,Boarding,8.0,17.0,BP 2222 BB,admin,2026-05-02 17:00:15,VR-002,Shipping Co,10\n".
            "501,VC-CLOSED,2026-05-03 09:00:00,3,JETTY_1,John Host,JETTY 1,Meeting,9.0,0,BP 3333 CC,admin,2026-05-03 09:00:00,VR-003,PT Tamu,10\n"
        );

        // Update manifest
        $manifest = json_decode(File::get($this->tempDir.'/manifest.json'), true);
        $manifest['tables']['ESSVisitorRegistration']['row_count'] = 3;
        File::put($this->tempDir.'/manifest.json', json_encode($manifest));

        // Run Delta Import
        $this->artisan('vms:import', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->assertExitCode(0);

        // 3. Verify VR-001 is now checked out
        $visit1->refresh();
        $this->assertNotNull($visit1->checked_out_at);
        $this->assertSame('2026-05-01 17:00:00', $visit1->checked_out_at->format('Y-m-d H:i:s'));

        // 4. Verify VR-003 was created and is open
        $visit3 = Visit::where('legacy_registration_no', 'VR-003')->first();
        $this->assertNotNull($visit3);
        $this->assertNull($visit3->checked_out_at);

        // 5. Total visits count is 3
        $this->assertSame(3, Visit::count());

        // 6. VC-OPEN should now be available (no longer used in any open visit)
        $cardOpen->refresh();
        $this->assertSame('available', $cardOpen->status);

        // 7. VC-CLOSED is now in_use (assigned to open visit VR-003)
        $cardClosed = VisitorCard::where('card_no', 'VC-CLOSED')->first();
        $this->assertNotNull($cardClosed);
        $this->assertSame('in_use', $cardClosed->status);

        // 8. Verification command passes cleanly
        $this->artisan('vms:import-verify', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->expectsOutputToContain('All 7 integrity checks PASSED.')
            ->assertExitCode(0);
    }
}
