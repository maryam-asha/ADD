<?php

namespace Add\Inventory\Events;

use Add\Inventory\Assets\Models\Asset;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An asset left the books (sold, scrapped…): a host's ledger closes it.
 * Sent once the transaction that caused it commits, so a ledger listening
 * never books what was rolled back.
 */
class AssetDisposed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Asset $asset) {}
}
