<?php

namespace App\Enums;

/**
 * Why a person is on the global blacklist shared between yards. Only these fixed
 * categories ever leave the yard (decision SEC-6d): the officer's free-text note stays
 * on the local server, because the two yards are separate companies.
 */
enum BlacklistReasonCategory: string
{
    case K3Violation = 'k3_violation';
    case AssetTheftDamage = 'asset_theft_damage';
    case Misconduct = 'misconduct';
    case Other = 'other';

    /** The name the gate screens show. */
    public function label(): string
    {
        return match ($this) {
            self::K3Violation => 'Pelanggaran K3',
            self::AssetTheftDamage => 'Pencurian/kerusakan aset',
            self::Misconduct => 'Perilaku tidak pantas',
            self::Other => 'Lainnya',
        };
    }
}
