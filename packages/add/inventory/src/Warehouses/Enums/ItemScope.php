<?php

namespace Add\Inventory\Warehouses\Enums;

/**
 * Which items a warehouse may hold: every stock item, or only the ones
 * listed for it (inv_warehouse_items).
 */
enum ItemScope: string
{
    case All = 'all';
    case Selected = 'selected';
}
