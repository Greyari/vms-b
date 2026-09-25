<?php

namespace App\Enums;

enum ImportSource: string
{
    case VmsOld = 'vms-old';
    case Pilot = 'pilot';
}
