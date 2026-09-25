<?php

namespace App\Services\Import\Transformers;

use App\Enums\LocationType;
use App\Models\Location;

class LocationTransformer
{
    /**
     * @param  array<string, mixed>  $row
     * @return array{source_key: string, attributes: array<string, mixed>}|null
     */
    public function transform(array $row): ?array
    {
        $name = trim((string) ($row['Description'] ?? ''));
        if ($name === '') {
            return null;
        }

        $isVessel = (string) ($row['isVessel'] ?? '0');
        $type = match ($isVessel) {
            '1' => LocationType::Location,
            '2' => LocationType::TugBoat,
            '3' => LocationType::Barge,
            default => LocationType::RepairVessel,
        };

        $imoNo = trim((string) ($row['IMONo'] ?? ''));
        if ($imoNo !== '') {
            $code = $imoNo;
        } else {
            $code = Location::codeFromName($name);
        }

        $sourceKey = $code;

        return [
            'source_key' => $sourceKey,
            'attributes' => [
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'call_sign' => trim((string) ($row['CallSign'] ?? '')) ?: null,
                'sign' => trim((string) ($row['Sign'] ?? '')) ?: null,
                'flag' => trim((string) ($row['NationalityFlag'] ?? '')) ?: null,
                'port' => trim((string) ($row['Port'] ?? '')) ?: null,
                'vessel_type' => trim((string) ($row['Type'] ?? '')) ?: null,
                'captain' => trim((string) ($row['Captain'] ?? '')) ?: null,
                'pic' => trim((string) ($row['Note'] ?? '')) ?: null,
                'local_agent_company' => trim((string) ($row['LocalCompanyAgent'] ?? '')) ?: null,
                'local_agent_name' => trim((string) ($row['LocalCompanyAgentName'] ?? '')) ?: null,
                'local_agent_phone' => trim((string) ($row['LocalCompanyAgentPhoneNo'] ?? '')) ?: null,
                'local_agent_location' => trim((string) ($row['LocalCompanyAgentLocation'] ?? '')) ?: null,
                'foreign_agent_company' => trim((string) ($row['ForeignCompanyAgent'] ?? '')) ?: null,
                'foreign_agent_name' => trim((string) ($row['ForeignCompanyAgentName'] ?? '')) ?: null,
                'foreign_agent_phone' => trim((string) ($row['ForeignCompanyAgentPhoneNo'] ?? '')) ?: null,
                'foreign_agent_location' => trim((string) ($row['ForeignCompanyAgentLocation'] ?? '')) ?: null,
                'is_active' => true,
            ],
        ];
    }
}
