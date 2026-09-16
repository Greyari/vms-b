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
}
