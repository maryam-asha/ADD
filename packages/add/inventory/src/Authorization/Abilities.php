<?php

namespace Add\Inventory\Authorization;

/**
 * Every ability the package's routes check with `can:`. The package
 * defines no Gates: the host grants these however it authorizes — in ADD,
 * spatie/laravel-permission resolves them as permissions, so a role is
 * given inventory access from the roles screen.
 */
final class Abilities
{
    public const CATALOG_VIEW = 'inventory.catalog.view';

    /** Categories, their fields, units, items, and the package's own locations. */
    public const CATALOG_MANAGE = 'inventory.catalog.manage';

    public const ASSETS_VIEW = 'inventory.assets.view';

    /** Registering, editing, moving and changing the status of assets. */
    public const ASSETS_MANAGE = 'inventory.assets.manage';

    /** Handing assets over and taking them back. */
    public const ASSETS_CUSTODY = 'inventory.assets.custody';

    /** Repairs, servicing and maintenance schedules; told when one is due. */
    public const ASSETS_MAINTENANCE = 'inventory.assets.maintenance';

    public const ASSETS_DISPOSE = 'inventory.assets.dispose';

    public const WAREHOUSES_VIEW = 'inventory.warehouses.view';

    /** Adding, editing, deactivating and deleting warehouses. */
    public const WAREHOUSES_MANAGE = 'inventory.warehouses.manage';

    public const STOCK_VIEW = 'inventory.stock.view';

    public const STOCK_RECEIVE = 'inventory.stock.receive';

    /** Issuing stock by hand (internal use, damaged, expired). */
    public const STOCK_ISSUE = 'inventory.stock.issue';

    public const STOCK_TRANSFER = 'inventory.stock.transfer';

    /** Recording a physical count. */
    public const STOCK_ADJUST = 'inventory.stock.adjust';

    /** Stock valuation and the asset register with book values. */
    public const REPORTS_VIEW = 'inventory.reports.view';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
