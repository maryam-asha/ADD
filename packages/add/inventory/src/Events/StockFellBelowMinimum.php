<?php

namespace Add\Inventory\Events;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An item in a warehouse fell below the minimum set for it: a host warns
 * whoever looks after the store. Sent once per dip (restocking to the
 * minimum re-arms it), after the movement's transaction commits.
 */
class StockFellBelowMinimum implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Item $item,
        public readonly Warehouse $warehouse,
        public readonly string $onHand,
        public readonly string $minimum,
    ) {}
}
