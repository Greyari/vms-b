<?php

namespace App\Enums;

/**
 * Card categories. VMS-old kept the category as free text in
 * ESSVisitorCardMaster.Note; the importer maps its spellings onto these
 * values and leaves anything else null, with the original text kept in
 * visitor_cards.note.
 */
enum CardType: string
{
    case Crew = 'crew';
    case VisitorGreen = 'visitor_green';
    case VisitorWhite = 'visitor_white';
    case TemporaryWorker = 'temporary_worker';
    case Special = 'special';
    case Commissioning = 'commissioning';
    case Visitor = 'visitor';

    /** The name the card master and the report filters show. */
    public function label(): string
    {
        return match ($this) {
            self::Crew => 'Crew',
            self::VisitorGreen => 'Visitor (green)',
            self::VisitorWhite => 'Visitor (white)',
            self::TemporaryWorker => 'Temporary worker',
            self::Special => 'Special',
            self::Commissioning => 'Commissioning',
            self::Visitor => 'Visitor',
        };
    }
}
