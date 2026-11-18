<?php

namespace Add\Inventory\Catalog\Enums;

/**
 * What an item is, decided by its category: an asset is tracked one
 * physical thing at a time (inv_assets), stock is counted by quantity per
 * location (inv_stock_balances).
 */
enum ItemKind: string
{
    case Asset = 'asset';
    case Stock = 'stock';
}
