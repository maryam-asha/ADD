<?php

namespace Add\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A month's depreciation is final (inventory:run-daily, once a month): a
 * host's ledger books each asset's DepreciationCalculator::forMonth().
 * Sent once the transaction that caused it commits, so a ledger listening
 * never books what was rolled back.
 */
class DepreciationMonthClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $year, public readonly int $month) {}
}
