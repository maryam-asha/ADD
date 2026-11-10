<?php

namespace App\Domain\Kitchen\Enums;

/**
 * pending ──accept──▶ preparing ──▶ ready ──▶ delivered; a pending order
 * may instead be rejected (by the kitchen) or cancelled (by the member, or
 * by the system when its bill closes first).
 */
enum KitchenOrderStatus: string
{
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Still on its way to the member. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Preparing, self::Ready], true);
    }

    /** Accepted, so its total is owed on its bill. */
    public function isOwed(): bool
    {
        return in_array($this, [self::Preparing, self::Ready, self::Delivered], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isOpen()),
        ));
    }

    /** @return list<string> */
    public static function owedValues(): array
    {
        return array_values(array_map(
            fn (self $status) => $status->value,
            array_filter(self::cases(), fn (self $status) => $status->isOwed()),
        ));
    }
}
