<?php

namespace App\Listeners;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Events\StockFellBelowMinimum;
use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\User;
use App\Services\Notification\NotificationService;

/**
 * Turns the inventory package's low-stock event into a staff notice: the
 * store's manager is told; a store with no manager tells whoever holds the
 * ability to manage warehouses (never picked by role name).
 *
 * Registered by event discovery only — never also by hand, which would
 * fire it twice.
 */
class NotifyWarehouseManagerOfLowStock
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function handle(StockFellBelowMinimum $event): void
    {
        $placeholders = [
            'item' => (string) $event->item->translate('name'),
            'warehouse' => (string) $event->warehouse->translate('name'),
            'on_hand' => $event->onHand,
            'minimum' => $event->minimum,
        ];
        $extra = ['warehouse_id' => $event->warehouse->id, 'item_id' => $event->item->id];

        $manager = $event->warehouse->manager_id === null ? null : User::query()->find($event->warehouse->manager_id);

        if ($manager !== null) {
            $this->notifications->sendToUser($manager, 'inventory.low_stock', $placeholders, $extra, NotificationAudience::Staff);

            return;
        }

        $this->notifications->sendToHolders(Abilities::WAREHOUSES_MANAGE, 'inventory.low_stock', $placeholders, $extra);
    }
}
