<?php

namespace Tests\Feature\Import;

use App\Enums\CardType;
use App\Enums\LocationType;
use App\Services\Import\Transformers\CardTransformer;
use App\Services\Import\Transformers\LocationTransformer;
use App\Services\Import\Transformers\NationalityTransformer;
use App\Services\Import\Transformers\StaffTransformer;
use Tests\TestCase;

class MasterDataTransformersTest extends TestCase
{
    public function test_transforms_nationality(): void
    {
        $transformer = new NationalityTransformer;

        $valid = $transformer->transform([
            'NationalityCode' => 'IDN',
            'Description' => 'INDONESIA  ',
            'Note' => '',
        ]);

        $this->assertNotNull($valid);
        $this->assertSame('IDN', $valid['source_key']);
        $this->assertSame('INDONESIA', $valid['attributes']['name']);

        $empty = $transformer->transform([
            'NationalityCode' => 'EMPTY',
            'Description' => '   ',
        ]);
        $this->assertNull($empty);
    }

    public function test_transforms_locations_and_vessels(): void
    {
        $transformer = new LocationTransformer;

        // 0 -> Repair Vessel
        $vessel = $transformer->transform([
            'IMONo' => '9123456',
            'Description' => 'MV OCEAN VOYAGER',
            'CallSign' => 'ABCD',
            'Sign' => 'SG',
            'NationalityFlag' => 'SINGAPORE',
            'Port' => 'SINGAPORE',
            'Type' => 'CONTAINER',
            'Captain' => 'Capt. Smith',
            'LocalCompanyAgent' => 'ASL LOCAL',
            'LocalCompanyAgentName' => 'Local Agent Contact',
            'LocalCompanyAgentPhoneNo' => '08123456789',
            'LocalCompanyAgentLocation' => 'Batam',
            'ForeignCompanyAgent' => 'FOREIGN CO',
            'ForeignCompanyAgentName' => 'Foreign Contact',
            'ForeignCompanyAgentPhoneNo' => '+6598765432',
            'ForeignCompanyAgentLocation' => 'Singapore',
            'Note' => 'Budi PIC',
            'isVessel' => '0',
        ]);

        $this->assertNotNull($vessel);
        $this->assertSame('9123456', $vessel['source_key']);
        $this->assertSame('9123456', $vessel['attributes']['code']);
        $this->assertSame('MV OCEAN VOYAGER', $vessel['attributes']['name']);
        $this->assertSame(LocationType::RepairVessel, $vessel['attributes']['type']);
        $this->assertSame('SINGAPORE', $vessel['attributes']['flag']);
        $this->assertSame('CONTAINER', $vessel['attributes']['vessel_type']);
        $this->assertSame('Budi PIC', $vessel['attributes']['pic']);
        $this->assertSame('ASL LOCAL', $vessel['attributes']['local_agent_company']);
        $this->assertSame('+6598765432', $vessel['attributes']['foreign_agent_phone']);

        // 1 -> Location without IMO
        $location = $transformer->transform([
            'IMONo' => '',
            'Description' => 'JETTY 01 NORTH',
            'isVessel' => '1',
        ]);

        $this->assertNotNull($location);
        $this->assertSame('JETTY_01_NORTH', $location['source_key']);
        $this->assertSame('JETTY_01_NORTH', $location['attributes']['code']);
        $this->assertSame(LocationType::Location, $location['attributes']['type']);

        // 2 -> Tugboat
        $tug = $transformer->transform([
            'IMONo' => 'TB-01',
            'Description' => 'TB POWER 1',
            'isVessel' => '2',
        ]);
        $this->assertSame(LocationType::TugBoat, $tug['attributes']['type']);

        // 3 -> Barge
        $barge = $transformer->transform([
            'IMONo' => 'BG-01',
            'Description' => 'BARGE JAYA',
            'isVessel' => '3',
        ]);
        $this->assertSame(LocationType::Barge, $barge['attributes']['type']);
    }

    public function test_transforms_staff(): void
    {
        $transformer = new StaffTransformer;

        // Normal unique staff
        $staff1 = $transformer->transform([
            'StaffID' => '446',
            'StaffFullName' => 'John Doe',
            'StaffGender' => '1',
            'StaffBirthDate' => '1985-06-15',
            'StaffCompany' => 'PT ASL',
            'StaffAddress' => 'Batam Center',
            'StaffOccupation' => 'Engineer',
            'VisitorStatus' => '1',
            'Note' => 'ENG-001',
        ]);

        $this->assertNotNull($staff1);
        $this->assertSame('446', $staff1['source_key']);
        $this->assertSame('ENG-001', $staff1['attributes']['staff_id']);
        $this->assertSame('John Doe', $staff1['attributes']['name']);
        $this->assertSame('M', $staff1['attributes']['gender']);
        $this->assertSame('1985-06-15', $staff1['attributes']['birth_date']);
        $this->assertTrue($staff1['attributes']['is_active']);

        // Duplicate known note STOREMAN
        $staff2 = $transformer->transform([
            'StaffID' => '102',
            'StaffFullName' => 'Store Worker',
            'StaffGender' => '2',
            'StaffBirthDate' => '1900-01-01',
            'VisitorStatus' => '2',
            'Note' => 'STOREMAN',
        ]);

        $this->assertSame('102', $staff2['source_key']);
        $this->assertSame('LEGACY-102', $staff2['attributes']['staff_id']);
        $this->assertSame('F', $staff2['attributes']['gender']);
        $this->assertNull($staff2['attributes']['birth_date']);
        $this->assertFalse($staff2['attributes']['is_active']);

        // Empty note
        $staff3 = $transformer->transform([
            'StaffID' => '103',
            'StaffFullName' => 'No Note Worker',
            'Note' => '',
            'VisitorStatus' => '1',
        ]);
        $this->assertSame('LEGACY-103', $staff3['attributes']['staff_id']);
    }

    public function test_transforms_cards(): void
    {
        $transformer = new CardTransformer;

        // Crew card
        $c1 = $transformer->transform([
            'CardNo' => 'vc-001',
            'AbsenteeismNo' => 'ABS-001',
            'ActiveStatus' => '1',
            'Note' => 'CREW CARD',
        ]);
        $this->assertNotNull($c1);
        $this->assertSame('VC-001', $c1['source_key']);
        $this->assertSame('VC-001', $c1['attributes']['card_no']);
        $this->assertSame('available', $c1['attributes']['status']);
        $this->assertSame(CardType::Crew, $c1['attributes']['card_type']);
        $this->assertTrue($c1['attributes']['is_active']);

        // Visitor green
        $c2 = $transformer->transform([
            'CardNo' => 'VC-002',
            'ActiveStatus' => '0',
            'Note' => 'Visitor Green',
        ]);
        $this->assertSame(CardType::VisitorGreen, $c2['attributes']['card_type']);
        $this->assertFalse($c2['attributes']['is_active']);

        // Other card types
        $this->assertSame(CardType::VisitorWhite, $transformer->transform(['CardNo' => 'C3', 'Note' => 'VISITOR WHITE'])['attributes']['card_type']);
        $this->assertSame(CardType::TemporaryWorker, $transformer->transform(['CardNo' => 'C4', 'Note' => 'Temporary Worker Pass'])['attributes']['card_type']);
        $this->assertSame(CardType::Special, $transformer->transform(['CardNo' => 'C5', 'Note' => 'SPECIAL PASS'])['attributes']['card_type']);
        $this->assertSame(CardType::Commissioning, $transformer->transform(['CardNo' => 'C6', 'Note' => 'Commissioning Pass'])['attributes']['card_type']);
        $this->assertSame(CardType::Visitor, $transformer->transform(['CardNo' => 'C7', 'Note' => 'VISITOR PASS'])['attributes']['card_type']);
        $this->assertNull($transformer->transform(['CardNo' => 'C8', 'Note' => 'Unknown Pass'])['attributes']['card_type']);

        // Empty card_no skipped
        $this->assertNull($transformer->transform(['CardNo' => '  ', 'Note' => 'CREW CARD']));
    }
}
