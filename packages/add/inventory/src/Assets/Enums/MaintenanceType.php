<?php

namespace Add\Inventory\Assets\Enums;

enum MaintenanceType: string
{
    // A repair: something broke.
    case Corrective = 'corrective';
    // Routine servicing, usually from a schedule.
    case Preventive = 'preventive';
}
