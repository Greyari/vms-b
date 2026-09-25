<?php

namespace App\Services\Import\Transformers;

class VesselArrivalTransformer
{
    public static function parseDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $val = trim($value);
        if (str_starts_with($val, '1900-01-01')) {
            return null;
        }

        $ts = strtotime($val);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $locationMap  [IMONo => uuid]
     * @param  array<string, string>  $locationNameMap  [IMONo => vessel_name]
     * @return array{source_key: string, attributes: array<string, mixed>}
     */
    public function transformArrival(array $row, array $locationMap = [], array $locationNameMap = []): array
    {
        $sequenceNo = trim((string) ($row['SequenceNo'] ?? ''));
        $imoNo = trim((string) ($row['IMONo'] ?? ''));

        $locationId = ($imoNo !== '' && isset($locationMap[$imoNo])) ? $locationMap[$imoNo] : null;
        $vesselName = ($imoNo !== '' && isset($locationNameMap[$imoNo]))
            ? $locationNameMap[$imoNo]
            : ($imoNo !== '' ? $imoNo : 'VESSEL');

        return [
            'source_key' => $sequenceNo,
            'attributes' => [
                'location_id' => $locationId,
                'vessel_name' => $vesselName,
                'sign_in_date' => self::parseDate($row['AssignmentSignIn'] ?? null),
                'sign_out_date' => self::parseDate($row['AssignmentSignOut'] ?? null),
                'sign_in_port' => trim((string) ($row['PortSignIn'] ?? '')) ?: null,
                'sign_out_port' => trim((string) ($row['PortSignOut'] ?? '')) ?: null,
                'captain' => trim((string) ($row['Captain'] ?? '')) ?: null,
                'local_agent_company' => trim((string) ($row['LocalCompanyAgent'] ?? '')) ?: null,
                'local_agent_name' => trim((string) ($row['LocalCompanyAgentName'] ?? '')) ?: null,
                'local_agent_phone' => trim((string) ($row['LocalCompanyAgentPhoneNo'] ?? '')) ?: null,
                'local_agent_location' => trim((string) ($row['LocalCompanyAgentLocation'] ?? '')) ?: null,
                'foreign_agent_company' => trim((string) ($row['ForeignCompanyAgent'] ?? '')) ?: null,
                'foreign_agent_name' => trim((string) ($row['ForeignCompanyAgentName'] ?? '')) ?: null,
                'foreign_agent_phone' => trim((string) ($row['ForeignCompanyAgentPhoneNo'] ?? '')) ?: null,
                'foreign_agent_location' => trim((string) ($row['ForeignCompanyAgentLocation'] ?? '')) ?: null,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $crewRows
     * @param  array<string, string>  $visitorMap  [legacy_visitor_id => uuid]
     * @return array{crew_visitor_ids: array<int, string>, unmapped_visitor_ids: array<int, string>}
     */
    public function extractCrewForArrival(string $arrivalSequenceNo, array $crewRows, array $visitorMap): array
    {
        $crewVisitorIds = [];
        $unmappedVisitorIds = [];

        foreach ($crewRows as $crewRow) {
            $txId = trim((string) ($crewRow['TransactionID'] ?? ''));
            if ($txId !== $arrivalSequenceNo) {
                continue;
            }

            $visId = trim((string) ($crewRow['VisitorID'] ?? ''));
            if ($visId === '') {
                continue;
            }

            if (isset($visitorMap[$visId])) {
                $crewVisitorIds[] = $visitorMap[$visId];
            } else {
                $unmappedVisitorIds[] = $visId;
            }
        }

        return [
            'crew_visitor_ids' => array_values(array_unique($crewVisitorIds)),
            'unmapped_visitor_ids' => array_values(array_unique($unmappedVisitorIds)),
        ];
    }
}
