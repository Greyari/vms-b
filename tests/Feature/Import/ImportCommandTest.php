<?php

namespace Tests\Feature\Import;

use App\Models\VesselArrival;
use App\Models\Visit;
use App\Models\VisitorCard;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        $this->tempDir = storage_path('framework/testing/vms_import_'.uniqid());
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_validates_required_source_path_and_manifest(): void
    {
        $this->artisan('vms:import', ['--source' => 'invalid', '--path' => $this->tempDir])
            ->expectsOutputToContain('The --source option must be either [vms-old] or [pilot].')
            ->assertExitCode(1);

        $this->artisan('vms:import', ['--source' => 'vms-old', '--path' => 'non/existent/path'])
            ->expectsOutputToContain('Directory not found')
            ->assertExitCode(1);

        $this->artisan('vms:import', ['--source' => 'vms-old', '--path' => $this->tempDir])
            ->expectsOutputToContain('manifest.json not found')
            ->assertExitCode(1);
    }

    public function test_imports_vms_old_data_and_syncs_card_status(): void
    {
        // 1. Prepare synthetic CSVs
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

        // 2 registrations:
        // - First is OPEN visit (CheckOutTime = 0) with VC-OPEN
        // - Second is CLOSED visit (CheckOutTime = 17.0) with VC-CLOSED
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

        File::put($this->tempDir.'/EmployeeMaster.csv',
            "EmployeeNo,StatusAdmin,FullName\n".
            "06914,0,ANG KOK ENG\n"
        );

        File::put($this->tempDir.'/manifest.json', json_encode([
            'source' => 'vms-old',
            'exported_at' => now()->toIso8601String(),
            'tables' => [
                'Nationality' => ['file' => 'Nationality.csv', 'row_count' => 2],
                'ESSVesselMaster' => ['file' => 'ESSVesselMaster.csv', 'row_count' => 2],
                'ESSStaffToVisit' => ['file' => 'ESSStaffToVisit.csv', 'row_count' => 1],
                'ESSVisitorCardMaster' => ['file' => 'ESSVisitorCardMaster.csv', 'row_count' => 2],
                'ESSVisitorMaster' => ['file' => 'ESSVisitorMaster.csv', 'row_count' => 2],
                'ESSVisitorRegistration' => ['file' => 'ESSVisitorRegistration.csv', 'row_count' => 2],
                'ESSVesselTransaction' => ['file' => 'ESSVesselTransaction.csv', 'row_count' => 1],
                'ESSCrewTransaction' => ['file' => 'ESSCrewTransaction.csv', 'row_count' => 1],
            ],
        ]));

        // 2. Run Import Command
        $this->artisan('vms:import', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
        ])->assertExitCode(0);

        // 3. Verify Database Records
        $this->assertDatabaseHas('nationalities', ['name' => 'INDONESIA']);
        $this->assertDatabaseHas('locations', ['code' => '9123456', 'name' => 'MV OCEANIC']);
        $this->assertDatabaseHas('locations', ['code' => 'JETTY_1', 'name' => 'JETTY 1']);
        $this->assertDatabaseHas('staff', ['staff_id' => 'HOST-01', 'name' => 'John Host']);
        $this->assertDatabaseHas('visitors', ['visitor_no' => 501, 'name' => 'Tamu Satu']);
        $this->assertDatabaseHas('visitors', ['visitor_no' => 502, 'name' => 'Kru Kapal']);

        $openVisit = Visit::where('legacy_registration_no', 'VR-001')->first();
        $this->assertNotNull($openVisit);
        $this->assertNull($openVisit->checked_out_at);

        $closedVisit = Visit::where('legacy_registration_no', 'VR-002')->first();
        $this->assertNotNull($closedVisit);
        $this->assertNotNull($closedVisit->checked_out_at);

        // 4. Verify Card Status Synchronization:
        // VC-OPEN is attached to open visit -> in_use
        $cardOpen = VisitorCard::where('card_no', 'VC-OPEN')->first();
        $this->assertNotNull($cardOpen);
        $this->assertSame('in_use', $cardOpen->status);

        // VC-CLOSED is attached to closed visit -> available
        $cardClosed = VisitorCard::where('card_no', 'VC-CLOSED')->first();
        $this->assertNotNull($cardClosed);
        $this->assertSame('available', $cardClosed->status);

        // 5. Verify Vessel Arrival and Crew Pivot
        $arrival = VesselArrival::first();
        $this->assertNotNull($arrival);
        $this->assertCount(1, $arrival->crew);
        $this->assertSame(502, $arrival->crew->first()->visitor_no);

        // 6. Verify Summary Activity Log
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'data_import',
            'subject_type' => 'import',
            'subject_id' => 'vms-old',
        ]);
    }

    public function test_dry_run_does_not_persist_records(): void
    {
        File::put($this->tempDir.'/Nationality.csv', "NationalityCode,Description,Note\nIDN,INDONESIA,\n");
        File::put($this->tempDir.'/manifest.json', json_encode(['source' => 'vms-old']));

        $this->artisan('vms:import', [
            '--source' => 'vms-old',
            '--path' => $this->tempDir,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('nationalities', ['name' => 'INDONESIA']);
    }
}
