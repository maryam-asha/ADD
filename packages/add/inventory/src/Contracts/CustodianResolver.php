<?php

namespace Add\Inventory\Contracts;

use Add\Inventory\Support\Place;

/**
 * Who an asset can be handed to: the host's people and organisations
 * (in ADD an employee, a member or a company). The package's default knows
 * none, which turns custody off.
 */
interface CustodianResolver
{
    /**
     * @return list<string>
     */
    public function types(): array;

    public function exists(Place $custodian): bool;

    /**
     * @return array{type: string, id: int, label: ?string}|null
     */
    public function describe(Place $custodian): ?array;
}
