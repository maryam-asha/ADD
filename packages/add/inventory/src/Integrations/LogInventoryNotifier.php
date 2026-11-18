<?php

namespace Add\Inventory\Integrations;

use Add\Inventory\Contracts\InventoryNotifier;
use Illuminate\Support\Facades\Log;

class LogInventoryNotifier implements InventoryNotifier
{
    public function notifyHolders(string $ability, string $key, array $placeholders = [], array $target = []): void
    {
        Log::info("inventory notification {$key} for holders of {$ability}", $placeholders + $target);
    }
}
