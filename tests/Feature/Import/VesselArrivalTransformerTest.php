<?php

namespace Tests\Feature\Import;

use App\Services\Import\Transformers\VesselArrivalTransformer;
use Tests\TestCase;

class VesselArrivalTransformerTest extends TestCase
{
    public function test_transforms_vessel_arrival(): void
    {
        $transformer = new VesselArrivalTransformer;

        $row = [
            'SequenceNo' => '1',
            'IMONo' => 'IMO-9123456',
            'AssignmentSignIn' => '2021-03-01 00:00:00',
            'AssignmentSignOut' => '2021-03-15 00:00:00',
            'PortSignIn' => 'Batam',
            'PortSignOut' => 'Singapore',
            'Captain' => 'Capt. Robert',
            'LocalCompanyAgent' => 'Local Agent Co',
            'LocalCompanyAgentName' => 'Budi Local',
            'LocalCompanyAgentPhoneNo' => '081234567',
            'LocalCompanyAgentLocation' => 'Batam Center',
            'ForeignCompanyAgent' => 'Foreign Co',
            'ForeignCompanyAgentName' => 'John Foreign',
            'ForeignCompanyAgentPhoneNo' => '+65987654',
            'ForeignCompanyAgentLocation' => 'Jurong',
        ];

        $locationMap = ['IMO-9123456' => 'loc-uuid-9123456'];
        $locationNameMap = ['IMO-9123456' => 'MV Oceanic Pioneer'];

        $result = $transformer->transformArrival($row, $locationMap, $locationNameMap);

        $this->assertSame('1', $result['source_key']);
        $this->assertSame('loc-uuid-9123456', $result['attributes']['location_id']);
        $this->assertSame('MV Oceanic Pioneer', $result['attributes']['vessel_name']);
        $this->assertSame('2021-03-01', $result['attributes']['sign_in_date']);
        $this->assertSame('2021-03-15', $result['attributes']['sign_out_date']);
        $this->assertSame('Batam', $result['attributes']['sign_in_port']);
        $this->assertSame('Singapore', $result['attributes']['sign_out_port']);
        $this->assertSame('Capt. Robert', $result['attributes']['captain']);
        $this->assertSame('Local Agent Co', $result['attributes']['local_agent_company']);
        $this->assertSame('+65987654', $result['attributes']['foreign_agent_phone']);
    }

    public function test_handles_placeholder_sign_out_date(): void
    {
        $transformer = new VesselArrivalTransformer;

        $row = [
            'SequenceNo' => '2',
            'IMONo' => 'IMO-9123456',
            'AssignmentSignIn' => '2021-04-01',
            'AssignmentSignOut' => '1900-01-01',
        ];

        $result = $transformer->transformArrival($row);

        $this->assertSame('2', $result['source_key']);
        $this->assertSame('2021-04-01', $result['attributes']['sign_in_date']);
        $this->assertNull($result['attributes']['sign_out_date']);
    }

    public function test_extracts_crew_by_transaction_id(): void
    {
        $transformer = new VesselArrivalTransformer;

        $crewRows = [
            ['SequenceNo' => '1', 'TransactionID' => '10', 'VisitorID' => '101'],
            ['SequenceNo' => '2', 'TransactionID' => '10', 'VisitorID' => '102'],
            ['SequenceNo' => '3', 'TransactionID' => '10', 'VisitorID' => '999'], // Unmapped
            ['SequenceNo' => '4', 'TransactionID' => '20', 'VisitorID' => '103'], // Different transaction
        ];

        $visitorMap = [
            '101' => 'visitor-uuid-101',
            '102' => 'visitor-uuid-102',
            '103' => 'visitor-uuid-103',
        ];

        $extracted = $transformer->extractCrewForArrival('10', $crewRows, $visitorMap);

        $this->assertCount(2, $extracted['crew_visitor_ids']);
        $this->assertContains('visitor-uuid-101', $extracted['crew_visitor_ids']);
        $this->assertContains('visitor-uuid-102', $extracted['crew_visitor_ids']);
        $this->assertNotContains('visitor-uuid-103', $extracted['crew_visitor_ids']);

        $this->assertCount(1, $extracted['unmapped_visitor_ids']);
        $this->assertContains('999', $extracted['unmapped_visitor_ids']);
    }
}
