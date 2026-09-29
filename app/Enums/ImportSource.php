<?php

namespace App\Enums;

enum ImportSource: string
{
    case VmsOld = 'vms-old';
    case Pilot = 'pilot';

    /**
     * The only yard whose server may import this source (decision PE-8): VMS-old is ASL's gate history,
     * the IIS pilot is CIS's. Importing one into the other yard would hand one company's visitor
     * records to the other.
     */
    public function yardCode(): string
    {
        return match ($this) {
            self::VmsOld => 'ASL',
            self::Pilot => 'CIS',
        };
    }
}
