<?php

namespace Add\Inventory\Stock\Services;

use Add\Inventory\Support\InventoryException;

/**
 * More was asked of a location than it holds. Stock never goes below zero:
 * a count that found less is an adjustment, not an issue.
 */
class InsufficientStockException extends InventoryException
{
    public string $available = '0';

    public static function for(string $available, string $requested, ?string $unit): self
    {
        $exception = new self(422, __('inventory::stock.insufficient', [
            'available' => $available,
            'requested' => $requested,
            'unit' => (string) $unit,
        ]));
        $exception->available = $available;

        return $exception;
    }
}
