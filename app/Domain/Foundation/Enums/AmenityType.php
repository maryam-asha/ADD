<?php

namespace App\Domain\Foundation\Enums;

/**
 * The fixed, known set of facility amenities a space can offer (Wi-Fi,
 * power outlets, ...) — distinct from Resource/ResourceCategory, which
 * models real equipment with a quantity and an admin-authored name (e.g. a
 * projector count), never toggled on/off per space. Which of these values
 * are even selectable for a given SpaceType is admin-editable data
 * (SpaceTypeAmenity), not hardcoded here; which are actually turned on for
 * one specific space lives in SpaceAmenity. String-backed and open-ended on
 * purpose, same reasoning as ResourceCategory.
 */
enum AmenityType: string
{
    case Wifi = 'wifi';
    case PowerOutlet = 'power_outlet';
    case Printing = 'printing';
    case ErgonomicChairs = 'ergonomic_chairs';
    case Locker = 'locker';
    case CafeAccess = 'cafe_access';
    case Ac = 'ac';
    case Stage = 'stage';
}
