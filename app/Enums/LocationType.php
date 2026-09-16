<?php

namespace App\Enums;

/**
 * VMS-old ESSVesselMaster.isVessel: 0 Repair Vessel, 1 Location, 2 Tug boat, 3 Barge.
 */
enum LocationType: string
{
    case RepairVessel = 'repair_vessel';
    case Location = 'location';
    case TugBoat = 'tug_boat';
    case Barge = 'barge';
}
