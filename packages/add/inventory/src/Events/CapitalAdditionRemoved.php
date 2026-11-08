<?php

namespace Add\Inventory\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A capital addition entered by mistake was removed: a host's ledger
 * reverses what it booked for it. Carries its figures, the row being gone.
 * Sent once the transaction that caused it commits.
 */
class CapitalAdditionRemoved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $additionId, public readonly int $assetId, public readonly string $amount, public readonly string $addedOn) {}
}
