<?php

namespace App\Http\Resources\Kitchen;

use App\Domain\Kitchen\Enums\OrderPaymentChoice;
use App\Domain\Kitchen\Models\KitchenOrder;
use Illuminate\Http\Request;

/**
 * One kitchen order as the kitchen sees it: the member's fields plus who
 * ordered it and how long it has waited — from when it was sent until now
 * while it is open, or until it was delivered, rejected or cancelled.
 *
 * Also what reception needs to decide: the `route` it was sent on, how it is
 * paid (`payment.wallet_owner_type` is whose wallet paid — `user` or
 * `company` — null while nothing has), `cash_due_points` for a cash order
 * not yet paid, and `bill` null when it has none.
 *
 * @mixin KitchenOrder
 */
class AdminKitchenOrderResource extends KitchenOrderResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->loadMissing('user', 'acceptedPayment.wallet');

        $until = $this->delivered_at ?? $this->rejected_at ?? $this->cancelled_at ?? now();

        return [
            ...parent::toArray($request),
            'bill' => $this->hasBill() ? ['type' => $this->billType(), 'id' => $this->billable_id] : null,
            'route' => $this->route?->value,
            'payment' => [
                'choice' => $this->payment_choice?->value,
                'wallet_owner_type' => $this->walletOwnerType(),
                'paid' => $this->paid_at !== null,
            ],
            'cash_due_points' => $this->payment_choice === OrderPaymentChoice::Cash && $this->paid_at === null ? $this->total_points : null,
            'member' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ],
            'waited_seconds' => $this->created_at === null ? 0 : max(0, (int) $this->created_at->diffInSeconds($until)),
        ];
    }

    /** Whose wallet paid the order — from its accepted payment; null when none has. */
    private function walletOwnerType(): ?string
    {
        return $this->acceptedPayment?->wallet?->owner_type->value;
    }
}
