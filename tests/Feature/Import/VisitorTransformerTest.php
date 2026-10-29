<?php

namespace Tests\Feature\Import;

use App\Enums\VisitorType;
use App\Services\Import\Transformers\VisitorTransformer;
use Tests\TestCase;

class VisitorTransformerTest extends TestCase
{
    public function test_transforms_single_regular_visitor(): void
    {
        $transformer = new VisitorTransformer;

        $row = [
            'VisitorID' => '12345',
            'VisitorIDType' => 'K',
            'VisitorIDNumber' => '3201-1234 5678-0001',
            'VisitorFullName' => 'Budi Santoso',
            'VisitorGender' => '1',
            'VisitorBirthDate' => '1990-05-20',
            'VisitorCompany' => 'PT Maju Terus',
            'VisitorAddress' => 'INDONESIA',
            'VisitorOccupation' => 'Technician',
            'VisitorType' => '1',
            'ExpiryDate' => '1900-01-01',
            'PhoneNo' => '08123456789',
            'PlaceofBirth' => 'Jakarta',
            'LastModifiedDate' => '2026-01-10 10:00:00',
        ];

        $result = $transformer->transformRow($row);

        $this->assertSame('12345', $result['source_key']);
        $this->assertSame(12345, $result['attributes']['visitor_no']);
        $this->assertSame('ktp', $result['attributes']['id_type']);
        $this->assertSame('3201123456780001', $result['attributes']['id_number']);
        $this->assertSame('Budi Santoso', $result['attributes']['name']);
        $this->assertSame('M', $result['attributes']['gender']);
        $this->assertSame('1990-05-20', $result['attributes']['birth_date']);
        $this->assertSame('INDONESIA', $result['attributes']['nationality']);
        $this->assertSame(VisitorType::Visitor, $result['attributes']['visitor_type']);
        $this->assertTrue($result['attributes']['id_lifetime']);
        $this->assertNull($result['attributes']['id_expires_at']);
        $this->assertFalse($result['attributes']['is_blacklisted']);
    }

    public function test_transforms_crew_visitor_with_vessel_matching(): void
    {
        $transformer = new VisitorTransformer;

        $row = [
            'VisitorID' => '22334',
            'VisitorIDType' => 'P',
            'VisitorIDNumber' => 'E-1234567',
            'VisitorFullName' => 'John Captain',
            'VisitorGender' => 'M',
            'VisitorBirthDate' => '1980-01-01',
            'VisitorType' => '2',
            'IMONo' => 'IMO-99999',
            'Certificate' => 'Master Mariner',
            'CertificateNo' => 'CERT-001',
            'SeamanBookNo' => 'SB-777',
            'SeamanBookExpiryDate' => '2030-05-01',
            'ExpiryDate' => '2028-12-31',
        ];

        $locationMap = ['IMO-99999' => 'vessel-uuid-99999'];
        $result = $transformer->transformRow($row, $locationMap);

        $this->assertSame('passport', $result['attributes']['id_type']);
        $this->assertSame('E1234567', $result['attributes']['id_number']);
        $this->assertSame(VisitorType::Crew, $result['attributes']['visitor_type']);
        $this->assertSame('vessel-uuid-99999', $result['attributes']['vessel_id']);
        $this->assertSame('Master Mariner', $result['attributes']['certificate']);
        $this->assertSame('CERT-001', $result['attributes']['certificate_no']);
        $this->assertSame('SB-777', $result['attributes']['seaman_book_no']);
        $this->assertSame('2030-05-01', $result['attributes']['seaman_book_expires_at']);
        $this->assertFalse($result['attributes']['id_lifetime']);
        $this->assertSame('2028-12-31', $result['attributes']['id_expires_at']);
    }

    public function test_merges_canonical_duplicates(): void
    {
        $transformer = new VisitorTransformer;

        $rows = [
            [
                'VisitorID' => '100',
                'VisitorIDType' => 'K',
                'VisitorIDNumber' => '1234-5678-90',
                'VisitorFullName' => 'Ahmad Legacy',
                'VisitorGender' => '1',
                'VisitorBirthDate' => '1990-01-01',
                'VisitorCompany' => 'PT Lama',
                'PhoneNo' => '0811111111',
                'PlaceofBirth' => '',
                'LastModifiedDate' => '2020-05-01 10:00:00',
                'VisitorType' => '1',
            ],
            [
                'VisitorID' => '250',
                'VisitorIDType' => 'K',
                'VisitorIDNumber' => '1234567890',
                'VisitorFullName' => 'Ahmad Updated',
                'VisitorGender' => '1',
                'VisitorBirthDate' => '1990-01-01',
                'VisitorCompany' => '',
                'PhoneNo' => '',
                'PlaceofBirth' => 'Batam',
                'LastModifiedDate' => '2026-02-01 12:00:00',
                'VisitorType' => '1',
            ],
        ];

        $grouped = $transformer->groupCanonical($rows);

        $this->assertCount(1, $grouped);
        $canonical = $grouped[0];

        $this->assertSame('250', $canonical['canonical_id']);
        $this->assertSame(250, $canonical['attributes']['visitor_no']);
        $this->assertSame('Ahmad Updated', $canonical['attributes']['name']);
        // Merged non-empty fields from row 100
        $this->assertSame('PT Lama', $canonical['attributes']['company']);
        $this->assertSame('Batam', $canonical['attributes']['birth_place']);
        $this->assertEqualsCanonicalizing(['250', '100'], $canonical['member_visitor_ids']);
    }

    public function test_applies_blacklist_if_any_duplicate_member_is_type_5(): void
    {
        $transformer = new VisitorTransformer;

        $rows = [
            [
                'VisitorID' => '300',
                'VisitorIDType' => 'K',
                'VisitorIDNumber' => '999988887777',
                'VisitorFullName' => 'Target Bad',
                'VisitorType' => '5', // Black List
                'LastModifiedDate' => '2021-01-01',
            ],
            [
                'VisitorID' => '400',
                'VisitorIDType' => 'K',
                'VisitorIDNumber' => '999988887777',
                'VisitorFullName' => 'Target Bad Newer',
                'VisitorType' => '1', // Regular
                'LastModifiedDate' => '2026-01-01',
            ],
        ];

        $grouped = $transformer->groupCanonical($rows);

        $this->assertCount(1, $grouped);
        $canonical = $grouped[0];

        $this->assertTrue($canonical['attributes']['is_blacklisted']);
        $this->assertSame('Diimpor dari VMS-old (tipe Black List)', $canonical['attributes']['blacklist_reason']);
        $this->assertSame(VisitorType::Others, $canonical['attributes']['visitor_type']);
    }

    public function test_cleans_placeholder_dates(): void
    {
        $transformer = new VisitorTransformer;

        $row = [
            'VisitorID' => '555',
            'VisitorIDType' => 'K',
            'VisitorIDNumber' => '11223344',
            'VisitorFullName' => 'Placeholder Guy',
            'VisitorBirthDate' => '1900-01-01 00:00:00',
            'ExpiryDate' => '1900-01-01',
            'IssueDate' => '1900-01-01',
        ];

        $result = $transformer->transformRow($row);

        $this->assertNull($result['attributes']['birth_date']);
        $this->assertNull($result['attributes']['id_issued_at']);
        $this->assertNull($result['attributes']['id_expires_at']);
        $this->assertTrue($result['attributes']['id_lifetime']);
    }
}
