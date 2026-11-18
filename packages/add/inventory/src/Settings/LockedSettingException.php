<?php

namespace Add\Inventory\Settings;

use Add\Inventory\Support\InventoryException;

/**
 * A setting data already depends on: names the key, so the settings page
 * shows the reason beside its field.
 */
class LockedSettingException extends InventoryException
{
    public function __construct(public readonly string $key, string $reason)
    {
        parent::__construct(422, $reason);
    }
}
