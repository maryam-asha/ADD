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
     * (`maintenance_due`), the host translates it. `$target` names the record
     * it is about (`target_type` `inventory_asset` and its `target_id`), so
     * the host can open it from the notice.
     *
     * @param  array<string, scalar>  $placeholders
     * @param  array{target_type?: string, target_id?: string}  $target
     */
    public function notifyHolders(string $ability, string $key, array $placeholders = [], array $target = []): void;
}
