<?php

namespace Add\Inventory\Assets\Enums;

/**
 * How an asset loses its value over its useful life: evenly each month
 * (straight line), or by twice that rate on what is left each month
 * (declining balance), never below its salvage value.
 */
enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';

    /**
     * The methods config inventory.assets.depreciation_methods allows,
     * unknown names dropped; straight line when none is left.
     *
     * @return list<self>
     */
    public static function enabled(): array
    {
        $enabled = array_values(array_filter(array_map(
            fn ($name) => is_string($name) ? self::tryFrom($name) : null,
            (array) config('inventory.assets.depreciation_methods'),
        )));

        return $enabled === [] ? [self::StraightLine] : $enabled;
    }

    /**
     * @return list<string>
     */
    public static function enabledValues(): array
    {
        return array_column(self::enabled(), 'value');
    }
}
