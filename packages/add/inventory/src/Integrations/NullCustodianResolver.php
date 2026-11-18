<?php

namespace Add\Inventory\Integrations;

use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Support\Place;

/**
 * No one to hand an asset to until the host says who its people are:
 * custody answers 422 until a resolver is bound.
 */
class NullCustodianResolver implements CustodianResolver
{
    public function types(): array
    {
        return [];
    }

    public function exists(Place $custodian): bool
    {
        return false;
    }

    public function describe(Place $custodian): ?array
    {
        return null;
    }
}
