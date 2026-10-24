<?php

namespace Add\Inventory\Stock\Enums;

/**
 * Why stock left. `consumption` and `sale` are recorded by the host when
 * its own operations use stock up; staff issuing by hand pick one of the
 * others.
 */
enum IssueReason: string
{
    case Consumption = 'consumption';
    case Sale = 'sale';
    case InternalUse = 'internal_use';
    case Damaged = 'damaged';
    case Expired = 'expired';
    case Other = 'other';

    /**
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::InternalUse, self::Damaged, self::Expired, self::Other];
    }
}
