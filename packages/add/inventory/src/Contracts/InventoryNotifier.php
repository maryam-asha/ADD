<?php

namespace Add\Inventory\Contracts;

/**
 * How the package tells people something needs doing (maintenance due, a
 * warranty ending). The host delivers it however it notifies; the default
 * only logs.
 */
interface InventoryNotifier
{
    /**
     * Everyone who holds `$ability`. `$key` names the message
     * (`maintenance_due`), the host translates it.
     *
     * @param  array<string, scalar>  $placeholders
     */
    public function notifyHolders(string $ability, string $key, array $placeholders = []): void;
}
