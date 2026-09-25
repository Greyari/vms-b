<?php

namespace App\Services\Import\Transformers;

class VisitTransformer
{
    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $visitorMap  [legacy_visitor_id => uuid]
     * @param  array<string, string>  $staffMap  [legacy_staff_id => uuid]
     * @param  array<string, string>  $locationMap  [code/imo => uuid]
     * @param  array<string, string>  $cardMap  [card_no => uuid]
     * @return array{source_key: string, attributes: array<string, mixed>, card_id: ?string, raw_card_no: ?string, is_orphan_card: bool}|null
     */
    public function transform(array $row, array $visitorMap, array $staffMap, array $locationMap, array $cardMap): ?array
    {
        $visitorId = trim((string) ($row['VisitorID'] ?? ''));
        $transactionDate = trim((string) ($row['TransactionDate'] ?? ''));
        $seqNo = trim((string) ($row['SequenceNo'] ?? ''));
        $sourceKey = "{$visitorId}|{$transactionDate}|{$seqNo}";

        if (! isset($visitorMap[$visitorId])) {
            return null;
        }

        $targetVisitorId = $visitorMap[$visitorId];

        $tdTimestamp = strtotime($transactionDate);
        $date = $tdTimestamp ? date('Y-m-d', $tdTimestamp) : null;
        $timeIn = $tdTimestamp ? date('H:i', $tdTimestamp) : null;

        $rawCheckOut = (float) ($row['CheckOutTime'] ?? 0);
        $checkedOutAt = null;
        $timeOut = null;

        if ($rawCheckOut > 0.0) {
            $outHours = (int) floor($rawCheckOut);
            $outMinutes = (int) round(($rawCheckOut - $outHours) * 60);
            if ($outMinutes >= 60) {
                $outHours += 1;
                $outMinutes -= 60;
            }
            $checkoutTimeOfDaySec = ($outHours * 3600) + ($outMinutes * 60);

            $lmd = trim((string) ($row['LastModifiedDate'] ?? ''));
            $usedLmd = false;

            if ($lmd !== '') {
                $lmdTs = strtotime($lmd);
                if ($lmdTs !== false) {
                    $lmdHours = (int) date('H', $lmdTs);
                    $lmdMinutes = (int) date('i', $lmdTs);
                    $lmdSeconds = (int) date('s', $lmdTs);
                    $lmdTimeOfDaySec = ($lmdHours * 3600) + ($lmdMinutes * 60) + $lmdSeconds;

                    $diffSec = abs($checkoutTimeOfDaySec - $lmdTimeOfDaySec);
                    if ($diffSec <= 120) {
                        $checkedOutAt = date('Y-m-d H:i:s', $lmdTs);
                        $timeOut = sprintf('%02d:%02d', $outHours, $outMinutes);
                        $usedLmd = true;
                    }
                }
            }

            if (! $usedLmd) {
                $checkInHours = $tdTimestamp ? (int) date('H', $tdTimestamp) : 0;
                $checkInMinutes = $tdTimestamp ? (int) date('i', $tdTimestamp) : 0;
                $checkInTimeOfDaySec = ($checkInHours * 3600) + ($checkInMinutes * 60);

                if ($checkoutTimeOfDaySec < $checkInTimeOfDaySec) {
                    $outDate = date('Y-m-d', strtotime($date.' +1 day'));
                } else {
                    $outDate = $date;
                }

                $checkedOutAt = sprintf('%s %02d:%02d:00', $outDate, $outHours, $outMinutes);
                $timeOut = sprintf('%02d:%02d', $outHours, $outMinutes);
            }
        }

        $vehicleNo = trim((string) ($row['VehicleNo'] ?? ''));
        $locationId = ($vehicleNo !== '' && isset($locationMap[$vehicleNo])) ? $locationMap[$vehicleNo] : null;
        $locationName = trim((string) ($row['VisitLocation'] ?? '')) ?: null;
        $carPlate = trim((string) ($row['Note'] ?? '')) ?: null;

        $staffId = trim((string) ($row['StaffID'] ?? ''));
        $targetStaffId = ($staffId !== '' && isset($staffMap[$staffId])) ? $staffMap[$staffId] : null;
        $hostName = trim((string) ($row['EmployeeToVisit'] ?? '')) ?: null;
        $visitorCompany = trim((string) ($row['VisitorCompany'] ?? '')) ?: null;
        $purpose = trim((string) ($row['VisitPurpose'] ?? '')) ?: null;

        $cardNo = strtoupper(trim((string) ($row['CardNo'] ?? '')));
        $cardId = null;
        $isOrphan = false;

        if ($cardNo !== '') {
            if (isset($cardMap[$cardNo])) {
                $cardId = $cardMap[$cardNo];
            } else {
                $isOrphan = true;
            }
        }

        $legacyRegNo = trim((string) ($row['VisitorRegistrationNo'] ?? '')) ?: null;

        return [
            'source_key' => $sourceKey,
            'attributes' => [
                'visitor_id' => $targetVisitorId,
                'staff_id' => $targetStaffId,
                'location_id' => $locationId,
                'vehicle_card_id' => null,
                'purpose' => $purpose,
                'date' => $date,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
                'checked_out_at' => $checkedOutAt,
                'vehicle_no' => $carPlate,
                'ticket_number' => null,
                'location_name' => $locationName,
                'host_name' => $hostName,
                'visitor_company' => $visitorCompany,
                'legacy_registration_no' => $legacyRegNo,
            ],
            'card_id' => $cardId,
            'raw_card_no' => $cardNo ?: null,
            'is_orphan_card' => $isOrphan,
        ];
    }
}
