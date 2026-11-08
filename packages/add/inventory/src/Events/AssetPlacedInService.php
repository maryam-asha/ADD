<?php

namespace Add\Inventory\Events;

use Add\Inventory\Assets\Models\Asset;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An asset entered service — registered, or approved from a draft: a
 * host's ledger books its cost.
 * Sent once the transaction that caused it commits, so a ledger listening
 * never books what was rolled back.
 */
class AssetPlacedInService implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Asset $asset) {}
}
