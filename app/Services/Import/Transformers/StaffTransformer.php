<?php

namespace App\Services\Import\Transformers;

class StaffTransformer
{
    /**
     * Known duplicate notes from VMS-old analysis.
     *
     * @var array<int, string>
     */
    protected const KNOWN_DUPLICATES = ['05779', '18031781', 'STOREMAN'];

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $extraDuplicateNotes
     * @return array{source_key: string, attributes: array<string, mixed>}|null
     */
    public function transform(array $row, array $extraDuplicateNotes = []): ?array
    {
        $staffId = trim((string) ($row['StaffID'] ?? ''));
        if ($staffId === '') {
            return null;
        }

        $fullName = trim((string) ($row['StaffFullName'] ?? ''));
        $note = trim((string) ($row['Note'] ?? ''));

        $duplicateList = array_merge(self::KNOWN_DUPLICATES, $extraDuplicateNotes);
        $isDuplicate = in_array(strtoupper($note), array_map('strtoupper', $duplicateList), true);

        if ($note === '' || $isDuplicate) {
            $computedStaffId = "LEGACY-{$staffId}";
        } else {
            $computedStaffId = $note;
        }

        $rawGender = strtoupper(trim((string) ($row['StaffGender'] ?? '')));
        $gender = match ($rawGender) {
            'M', '1' => 'M',
            'F', '2' => 'F',
            default => null,
        };

        $rawBirthDate = trim((string) ($row['StaffBirthDate'] ?? ''));
        $birthDate = null;
        if ($rawBirthDate !== '' && ! str_starts_with($rawBirthDate, '1900-01-01')) {
            $time = strtotime($rawBirthDate);
            if ($time !== false) {
                $birthDate = date('Y-m-d', $time);
            }
        }

        $visitorStatus = (int) ($row['VisitorStatus'] ?? 0);
        $isActive = ($visitorStatus === 1);

        return [
            'source_key' => $staffId,
            'attributes' => [
                'staff_id' => $computedStaffId,
                'name' => $fullName,
                'gender' => $gender,
                'birth_date' => $birthDate,
                'company' => trim((string) ($row['StaffCompany'] ?? '')) ?: null,
                'address' => trim((string) ($row['StaffAddress'] ?? '')) ?: null,
                'occupation' => trim((string) ($row['StaffOccupation'] ?? '')) ?: null,
                'is_active' => $isActive,
            ],
        ];
    }
}
