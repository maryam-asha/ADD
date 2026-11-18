<?php

namespace App\Domain\Kitchen\Enums;

/**
 * pending ──accept──▶ preparing ──▶ ready ──▶ delivered; a pending order
 * may instead be rejected (by the kitchen) or cancelled (by the member, or
 * by the system when its bill closes first). An order sent without a bill
 * first waits: for its payment (awaiting_payment) or for reception to let it
 * through (awaiting_reception), and only then becomes pending.
 */
enum KitchenOrderStatus: string
{
    case AwaitingPayment = 'awaiting_payment';
    case AwaitingReception = 'awaiting_reception';
    case Pending = 'pending';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Still on its way to the member. */
    public function isOpen(): bool
    {
        return in_array($this, [self::AwaitingPayment, self::AwaitingReception, self::Pending, self::Preparing, self::Ready], true);
    }

    /** Past the waiting stages: the kitchen has been handed it. */
    public function reachesKitchen(): bool
    {
        return ! in_array($this, [self::AwaitingPayment, self::AwaitingReception], true);
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

    /**
     * What the kitchen board shows: orders waiting on payment or reception
     * are not the kitchen's yet.
     *
     * @return list<string>
     */
    public static function kitchenBoardValues(): array
    {
        return [self::Pending->value, self::Preparing->value, self::Ready->value];
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
