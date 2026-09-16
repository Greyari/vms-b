<?php

namespace App\Support;

/**
 * One spelling per identity document. Gate staff type KTP, SIM and passport
 * numbers with spaces and dashes ("3210-0000 0000 0001"), VMS-old's duplicate
 * check already stripped dashes, and 148 duplicate identity groups in the
 * legacy data differ only by that punctuation. Every path that writes or looks
 * up visitors.id_number goes through here, so the (id_type, id_number) unique
 * index is what actually prevents duplicates.
 */
final class IdentityNumber
{
    public static function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtoupper((string) preg_replace('/[\s\-]+/u', '', $value));
    }
}
