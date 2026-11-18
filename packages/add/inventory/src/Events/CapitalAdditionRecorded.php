<?php

namespace Add\Inventory\Events;

use Add\Inventory\Assets\Models\AssetAddition;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Money spent on an asset raised its cost: a host's ledger books it.
 * Sent once the transaction that caused it commits, so a ledger listening
 * never books what was rolled back.
 */
class CapitalAdditionRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly AssetAddition $addition) {}
}
