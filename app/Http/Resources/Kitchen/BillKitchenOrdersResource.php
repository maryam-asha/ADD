<?php

namespace App\Http\Resources\Kitchen;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Kitchen\Models\KitchenOrder;
use App\Domain\Kitchen\Services\KitchenOrderSettlement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The kitchen orders of one coworking session or booking, as the member's
 * session and booking screens show them (coworking-sessions/current, its
 * checkout, a session's detail, a booking's detail): the money summary the
 * cards already read (KitchenOrderSettlement::summaryFor() — accepted orders
 * only) and `list`, every order sent to it whatever its status, oldest first,
 * each as KitchenOrderResource with its items and their quantities. The app
 * follows each order's status over `kitchen_order.updated` on
 * private-member.{id}, which names the session it is on.
 *
 * With no bill (a scan still waiting on reception) it is the empty block.
 *
 * @property Booking|WalkinSession|null $resource
 */
class BillKitchenOrdersResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return KitchenOrderSettlement::emptySummary() + ['list' => []];
        }

        $orders = app(KitchenOrderSettlement::class);

        return $orders->summaryFor($this->resource) + [
            'list' => $orders->allOn($this->resource)
                ->map(fn (KitchenOrder $order) => (new KitchenOrderResource($order))->resolve($request))
                ->values()
                ->all(),
        ];
    }
}
