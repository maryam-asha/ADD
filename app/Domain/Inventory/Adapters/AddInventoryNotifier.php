<?php

namespace App\Domain\Inventory\Adapters;

use Add\Inventory\Contracts\InventoryNotifier;
use App\Domain\Identity\Models\User;
use App\Services\Notification\NotificationService;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Inventory notifications through ADD's own channels (in-app + push), in
 * each recipient's preferred language. Package keys are namespaced under
 * `inventory.` in lang/{en,ar}/notifications.php.
 */
class AddInventoryNotifier implements InventoryNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function notifyHolders(string $ability, string $key, array $placeholders = []): void
    {
        try {
            $users = User::permission($ability)->where('status', 'active')->get();
        } catch (PermissionDoesNotExist) {
            // permissions:sync has not run yet: nobody can hold it.
            return;
        }

        if ($users->isNotEmpty()) {
            $this->notifications->sendToUsers($users, "inventory.{$key}", $placeholders);
        }
    }
}
