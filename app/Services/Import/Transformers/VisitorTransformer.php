<?php

namespace App\Services\Import\Transformers;

use App\Enums\VisitorType;
use App\Support\IdentityNumber;

class VisitorTransformer
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
     * @param  array<string, string>  $locationMap  [IMONo => vessel_uuid]
     * @return array{source_key: string, attributes: array<string, mixed>, last_modified: ?string}
     */
    public function transformRow(array $row, array $locationMap = []): array
    {
        $visitorId = trim((string) ($row['VisitorID'] ?? ''));
        $rawIdType = strtoupper(trim((string) ($row['VisitorIDType'] ?? '')));
        $idType = match ($rawIdType) {
            'S' => 'sim',
            'P' => 'passport',
            default => 'ktp',
        };

        $idNumber = IdentityNumber::normalize($row['VisitorIDNumber'] ?? '');

        $rawExpiry = trim((string) ($row['ExpiryDate'] ?? ''));
        if (blank($rawExpiry) || str_starts_with($rawExpiry, '1900-01-01')) {
            $idLifetime = true;
            $idExpiresAt = null;
        } else {
            $idLifetime = false;
            $idExpiresAt = self::parseDate($rawExpiry);
        }

        $rawGender = strtoupper(trim((string) ($row['VisitorGender'] ?? '')));
        $gender = match ($rawGender) {
            'M', '1' => 'M',
            'F', '2' => 'F',
            default => null,
        };

        $rawType = (int) ($row['VisitorType'] ?? 1);
        if ($rawType === 5) {
            $isBlacklisted = true;
            $blacklistReason = 'Diimpor dari VMS-old (tipe Black List)';
            $visitorType = VisitorType::Others;
        } else {
            $isBlacklisted = false;
            $blacklistReason = null;
            $visitorType = match ($rawType) {
                2 => VisitorType::Crew,
                3 => VisitorType::TemporaryWorker,
                4 => VisitorType::Supplier,
                6 => VisitorType::Others,
                default => VisitorType::Visitor,
            };
        }

        $address = trim((string) ($row['VisitorAddress'] ?? ''));
        $nationality = ($address === '' || $address === '-') ? null : $address;

        $imoNo = trim((string) ($row['IMONo'] ?? ''));
        $vesselId = ($imoNo !== '' && isset($locationMap[$imoNo])) ? $locationMap[$imoNo] : null;

        $attributes = [
            'visitor_no' => (int) $visitorId,
            'id_type' => $idType,
            'id_number' => $idNumber,
            'name' => trim((string) ($row['VisitorFullName'] ?? '')),
            'company' => trim((string) ($row['VisitorCompany'] ?? '')) ?: null,
            'visitor_type' => $visitorType,
            'gender' => $gender,
            'birth_date' => self::parseDate($row['VisitorBirthDate'] ?? null),
            'birth_place' => trim((string) ($row['PlaceofBirth'] ?? '')) ?: null,
            'nationality' => $nationality,
            'occupation' => trim((string) ($row['VisitorOccupation'] ?? '')) ?: null,
            'phone' => trim((string) ($row['PhoneNo'] ?? '')) ?: null,
            'id_issued_at' => self::parseDate($row['IssueDate'] ?? null),
            'id_expires_at' => $idExpiresAt,
            'id_lifetime' => $idLifetime,
            'is_blacklisted' => $isBlacklisted,
            'blacklist_reason' => $blacklistReason,
            'vessel_id' => $vesselId,
            'certificate' => trim((string) ($row['Certificate'] ?? '')) ?: null,
            'certificate_no' => trim((string) ($row['CertificateNo'] ?? '')) ?: null,
            'seaman_book_no' => trim((string) ($row['SeamanBookNo'] ?? '')) ?: null,
            'seaman_book_expires_at' => self::parseDate($row['SeamanBookExpiryDate'] ?? null),
        ];

        return [
            'source_key' => $visitorId,
            'attributes' => $attributes,
            'last_modified' => trim((string) ($row['LastModifiedDate'] ?? '')) ?: null,
        ];
    }

    /**
     * Groups raw visitor rows into canonical visitor entities.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, string>  $locationMap
     * @return array<int, array{canonical_id: string, source_key: string, attributes: array<string, mixed>, member_visitor_ids: array<int, string>, is_duplicate: bool}>
     */
    public function groupCanonical(array $rows, array $locationMap = []): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $transformed = $this->transformRow($row, $locationMap);
            $visitorId = $transformed['source_key'];
            $idType = $transformed['attributes']['id_type'];
            $idNumber = $transformed['attributes']['id_number'];

            $groupKey = $idNumber !== '' ? "{$idType}|{$idNumber}" : "NOID-{$visitorId}";

            $groups[$groupKey][] = $transformed;
        }

        $results = [];

        foreach ($groups as $members) {
            // Sort by LastModifiedDate DESC, then VisitorID (int) DESC
            usort($members, function ($a, $b) {
                $timeA = $a['last_modified'] ? strtotime($a['last_modified']) : 0;
                $timeB = $b['last_modified'] ? strtotime($b['last_modified']) : 0;

                if ($timeA !== $timeB) {
                    return $timeB <=> $timeA;
                }

                return ((int) $b['source_key']) <=> ((int) $a['source_key']);
            });

            $canonical = $members[0];
            $canonicalAttrs = $canonical['attributes'];
            $memberVisitorIds = [];
            $hasBlacklist = false;

            foreach ($members as $member) {
                $memberVisitorIds[] = $member['source_key'];

                if ($member['attributes']['is_blacklisted']) {
                    $hasBlacklist = true;
                }

                // Merge empty fields into canonical
                foreach ($member['attributes'] as $field => $val) {
                    if ($field === 'is_blacklisted' || $field === 'blacklist_reason' || $field === 'visitor_no') {
                        continue;
                    }

                    if (($canonicalAttrs[$field] === null || $canonicalAttrs[$field] === '') && ($val !== null && $val !== '')) {
                        $canonicalAttrs[$field] = $val;
                    }
                }
            }

            if ($hasBlacklist) {
                $canonicalAttrs['is_blacklisted'] = true;
                $canonicalAttrs['blacklist_reason'] = 'Diimpor dari VMS-old (tipe Black List)';
                $canonicalAttrs['visitor_type'] = VisitorType::Others;
            }

            $results[] = [
                'canonical_id' => $canonical['source_key'],
                'source_key' => $canonical['source_key'],
                'attributes' => $canonicalAttrs,
                'member_visitor_ids' => $memberVisitorIds,
                'is_duplicate' => count($memberVisitorIds) > 1,
            ];
        }

        return $results;
    }
}
