<?php

namespace Add\Inventory\Support;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A business rule said no. An HttpException on purpose: any Laravel host
 * renders it as `{"message": ...}` with its status, without registering a
 * renderer for the package.
 *
 * The key is a full translation key (`inventory::stock.insufficient`),
 * translated at the moment of throwing.
 */
class InventoryException extends HttpException
{
    /**
     * @param  array<string, mixed>  $params
     */
    public static function fail(string $key, array $params = [], int $status = 422): never
    {
        throw new static($status, __($key, $params));
    }
}
