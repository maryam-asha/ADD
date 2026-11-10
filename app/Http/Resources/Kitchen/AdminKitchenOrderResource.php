<?php

namespace App\Http\Resources\Kitchen;

use App\Domain\Kitchen\Models\KitchenOrder;
use Illuminate\Http\Request;

/**
 * One kitchen order as the kitchen sees it: the member's fields plus who
 * ordered it and how long it has waited — from when it was sent until now
 * while it is open, or until it was delivered, rejected or cancelled.
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
        $this->loadMissing('user');

        $until = $this->delivered_at ?? $this->rejected_at ?? $this->cancelled_at ?? now();

        return [
            ...parent::toArray($request),
            'member' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'phone' => $this->user->phone,
            ],
            'waited_seconds' => $this->created_at === null ? 0 : max(0, (int) $this->created_at->diffInSeconds($until)),
        ];
    }
}
