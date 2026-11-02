<?php

namespace App\Domain\Inventory\Adapters;

use Add\Inventory\Contracts\InventoryNotifier;
use App\Services\Notification\NotificationService;

/**
 * Inventory notifications through ADD's own channels, read in the dashboard
 * in each reader's language. Package keys are namespaced under `inventory.`
 * in lang/{en,ar}/notifications.php.
 */
class AddInventoryNotifier implements InventoryNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function notifyHolders(string $ability, string $key, array $placeholders = []): void
    {
        $this->notifications->sendToHolders($ability, "inventory.{$key}", $placeholders);
    }
}
