<?php

namespace App\Http\Resources\Kitchen;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Kitchen\Models\QrPoint;
use App\Domain\Retail\Models\SaleItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The member's kitchen menu: the branch, whether they may order now (and
 * why not), the place a scanned QR names, the bills they could order onto,
 * and what the kitchen holds. Built from an array:
 * `{access: MenuAccess::for(), qr: ?QrPoint, items: KitchenMenu::for()}`.
 *
 * @property array{access: array{branch_id: ?int, can_order: bool, reason: ?string, bills: list<Booking|WalkinSession>}, qr: ?QrPoint, items: list<array{sale_item: SaleItem, available: string}>} $resource
 */
class KitchenMenuResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        ['access' => $access, 'qr' => $qr, 'items' => $items] = $this->resource;
        $branch = $access['branch_id'] === null ? null : Branch::query()->find($access['branch_id']);

        return [
            'branch' => $branch === null ? null : ['id' => $branch->id, 'name' => $branch->translate('name')],
            'can_order' => $access['can_order'],
            'reason' => $access['reason'],
            'place' => $qr === null ? null : [
                'type' => $qr->place_type->value,
                'id' => $qr->place_id,
                'label' => $qr->placeLabel(),
            ],
            'bills' => array_map(fn (Booking|WalkinSession $bill) => [
                'type' => $bill->coworkingType(),
                'id' => $bill->id,
                'label' => $bill->space->displayTitle(),
            ], $access['bills']),
            'items' => array_map(fn (array $entry) => [
                'sale_item_id' => $entry['sale_item']->id,
                'name' => $entry['sale_item']->item->translate('name'),
                'image_url' => $entry['sale_item']->item->image_url,
                'price_points' => $entry['sale_item']->price_points,
                // Stock items are counted whole; a fraction left is not one more.
                'available_quantity' => (int) bcadd($entry['available'], '0', 0),
            ], $items),
        ];
    }
}
