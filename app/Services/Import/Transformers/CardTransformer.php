<?php

namespace App\Services\Import\Transformers;

use App\Enums\CardType;

class CardTransformer
{
    /**
     * Normalize CardType from legacy Note text.
     */
    public static function normalizeCardType(?string $note): ?CardType
    {
        if (blank($note)) {
            return null;
        }

        $upper = strtoupper(trim($note));

        // CREW: contains ("CR" and "CARD") or "CREW" or "CRWE"
        if (str_contains($upper, 'CREW') || str_contains($upper, 'CRWE') || (str_contains($upper, 'CR') && str_contains($upper, 'CARD'))) {
            return CardType::Crew;
        }

        if (str_contains($upper, 'VISITOR GREEN') || str_contains($upper, 'GREEN')) {
            return CardType::VisitorGreen;
        }

        if (str_contains($upper, 'VISITOR WHITE') || str_contains($upper, 'WHITE')) {
            return CardType::VisitorWhite;
        }

        if (str_contains($upper, 'TEMPORARY')) {
            return CardType::TemporaryWorker;
        }

        if (str_contains($upper, 'SPECIAL')) {
            return CardType::Special;
        }

        if (str_contains($upper, 'COMMISSIONING')) {
            return CardType::Commissioning;
        }

        if (str_contains($upper, 'VISITOR PASS') || str_contains($upper, 'VISITOR')) {
            return CardType::Visitor;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{source_key: string, attributes: array<string, mixed>}|null
     */
    public function transform(array $row): ?array
    {
        $cardNo = strtoupper(trim((string) ($row['CardNo'] ?? '')));
        if ($cardNo === '') {
            return null;
        }

        $note = trim((string) ($row['Note'] ?? '')) ?: null;
        $cardType = self::normalizeCardType($note);
        $activeStatus = (int) ($row['ActiveStatus'] ?? 0);
        $isActive = ($activeStatus === 1);

        return [
            'source_key' => $cardNo,
            'attributes' => [
                'card_no' => $cardNo,
                'absenteeism_no' => trim((string) ($row['AbsenteeismNo'] ?? '')) ?: null,
                'status' => 'available',
                'card_type' => $cardType,
                'note' => $note,
                'is_active' => $isActive,
            ],
        ];
    }
}
