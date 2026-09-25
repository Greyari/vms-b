<?php

namespace Tests\Feature\Import;

use App\Services\Import\Transformers\VisitTransformer;
use Tests\TestCase;

class VisitTransformerTest extends TestCase
{
    private array $visitorMap = ['101' => 'visitor-uuid-101'];

    private array $staffMap = ['55' => 'staff-uuid-55'];

    private array $locationMap = ['IMO-123' => 'location-uuid-123'];

    private array $cardMap = ['VC-01' => 'card-uuid-01'];

    public function test_skips_when_visitor_is_not_mapped(): void
    {
        $transformer = new VisitTransformer;

        $row = [
            'VisitorID' => '99999', // Not in map
            'TransactionDate' => '2026-05-10 08:00:00',
            'SequenceNo' => '1',
            'CheckOutTime' => '0',
        ];

        $result = $transformer->transform($row, $this->visitorMap, $this->staffMap, $this->locationMap, $this->cardMap);
        $this->assertNull($result);
    }

    public function test_transforms_open_visit_when_checkout_time_is_zero(): void
    {
        $transformer = new VisitTransformer;

        $row = [
            'VisitorID' => '101',
            'TransactionDate' => '2026-05-10 08:30:00',
            'SequenceNo' => '12',
            'VehicleNo' => 'IMO-123',
            'EmployeeToVisit' => 'Mr. Manager',
            'VisitLocation' => 'MV Oceanic',
            'VisitPurpose' => 'Meeting',
            'CheckInTime' => '8.5',
            'CheckOutTime' => '0',
            'Note' => 'BP 1234 XY', // Vehicle plate
            'LastModifiedDate' => '2026-05-10 08:30:00',
            'VisitorRegistrationNo' => 'REG-9999',
            'VisitorCompany' => 'PT Marine',
            'StaffID' => '55',
            'CardNo' => 'VC-01',
        ];

        $result = $transformer->transform($row, $this->visitorMap, $this->staffMap, $this->locationMap, $this->cardMap);

        $this->assertNotNull($result);
        $this->assertSame('101|2026-05-10 08:30:00|12', $result['source_key']);
        $this->assertSame('visitor-uuid-101', $result['attributes']['visitor_id']);
        $this->assertSame('staff-uuid-55', $result['attributes']['staff_id']);
        $this->assertSame('location-uuid-123', $result['attributes']['location_id']);
        $this->assertSame('2026-05-10', $result['attributes']['date']);
        $this->assertSame('08:30', $result['attributes']['time_in']);
        $this->assertNull($result['attributes']['time_out']);
        $this->assertNull($result['attributes']['checked_out_at']);
        $this->assertSame('BP 1234 XY', $result['attributes']['vehicle_no']);
        $this->assertSame('MV Oceanic', $result['attributes']['location_name']);
        $this->assertSame('Mr. Manager', $result['attributes']['host_name']);
        $this->assertSame('PT Marine', $result['attributes']['visitor_company']);
        $this->assertSame('Meeting', $result['attributes']['purpose']);
        $this->assertSame('REG-9999', $result['attributes']['legacy_registration_no']);
        $this->assertNull($result['attributes']['ticket_number']);
        $this->assertSame('card-uuid-01', $result['card_id']);
    }

    public function test_reconstructs_checkout_timestamp_using_last_modified_date_when_within_tolerance(): void
    {
        $transformer = new VisitTransformer;

        $row = [
            'VisitorID' => '101',
            'TransactionDate' => '2026-05-10 08:00:00',
            'SequenceNo' => '15',
            'CheckInTime' => '8.0',
            'CheckOutTime' => '17.5', // 17:30
            'LastModifiedDate' => '2026-05-10 17:30:45', // 45 seconds difference
        ];

        $result = $transformer->transform($row, $this->visitorMap, $this->staffMap, $this->locationMap, $this->cardMap);

        $this->assertNotNull($result);
        $this->assertSame('2026-05-10 17:30:45', $result['attributes']['checked_out_at']);
        $this->assertSame('17:30', $result['attributes']['time_out']);
    }

    public function test_reconstructs_checkout_timestamp_with_day_rollover_when_checkout_time_less_than_checkin_time(): void
    {
        $transformer = new VisitTransformer;

        $row = [
            'VisitorID' => '101',
            'TransactionDate' => '2026-05-10 22:00:00',
            'SequenceNo' => '18',
            'CheckInTime' => '22.0',
            'CheckOutTime' => '4.0', // 04:00 AM next day
            'LastModifiedDate' => '2026-08-01 12:00:00', // Modified months later
        ];

        $result = $transformer->transform($row, $this->visitorMap, $this->staffMap, $this->locationMap, $this->cardMap);

        $this->assertNotNull($result);
        $this->assertSame('2026-05-11 04:00:00', $result['attributes']['checked_out_at']);
        $this->assertSame('04:00', $result['attributes']['time_out']);
    }

    public function test_flags_orphan_card(): void
    {
        $transformer = new VisitTransformer;

        $row = [
            'VisitorID' => '101',
            'TransactionDate' => '2026-05-10 08:00:00',
            'SequenceNo' => '20',
            'CardNo' => 'UNKNOWN-CARD-99',
            'CheckOutTime' => '0',
        ];

        $result = $transformer->transform($row, $this->visitorMap, $this->staffMap, $this->locationMap, $this->cardMap);

        $this->assertNotNull($result);
        $this->assertNull($result['card_id']);
        $this->assertSame('UNKNOWN-CARD-99', $result['raw_card_no']);
        $this->assertTrue($result['is_orphan_card']);
    }
}
