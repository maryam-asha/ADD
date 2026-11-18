<?php

namespace Add\Inventory\Support\Concerns;

use LogicException;

/**
 * A ledger row: written once, never changed or removed, so the history it
 * records stays the history that happened. A correction is a new row.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn () => throw new LogicException(static::class.' rows are append-only.'));
        static::deleting(fn () => throw new LogicException(static::class.' rows are append-only.'));
    }
}
