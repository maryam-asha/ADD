<?php

namespace Add\Inventory\Assets\Enums;

/**
 * Where an asset stands. `in_maintenance` is set and cleared only by
 * opening and closing a maintenance record, `disposed` only by disposing of
 * it, and nothing leaves `disposed`.
 */
enum AssetStatus: string
{
    case InService = 'in_service';
    case InMaintenance = 'in_maintenance';
    // Still owned, out of use (stored, kept as a spare).
    case Retired = 'retired';
    case Lost = 'lost';
    case Disposed = 'disposed';

    /**
     * The statuses PATCH assets/{asset}/status may set; the other two have
     * their own flows.
     *
     * @return list<self>
     */
    public static function settable(): array
    {
        return [self::InService, self::Retired, self::Lost];
    }
}
