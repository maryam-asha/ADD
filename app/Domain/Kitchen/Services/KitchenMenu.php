<?php

namespace App\Domain\Kitchen\Services;

use Add\Inventory\Stock\Services\StockService;
use App\Domain\Kitchen\Models\KitchenStore;
use App\Domain\Retail\Models\SaleItem;

/**
 * What a branch's kitchen can serve right now: the active sale items with
 * stock in its kitchen store, by name.
 */
class KitchenMenu
{
    public function __construct(private readonly StockService $stock) {}

    /**
     * @return list<array{sale_item: SaleItem, available: string}>
     */
    public function for(int $branchId): array
    {
        $store = KitchenStore::forBranch($branchId);

        if ($store === null) {
            return [];
        }

        $place = $store->place();
        $menu = [];

        foreach (SaleItem::query()->active()->with('item')->get() as $saleItem) {
            $available = $this->stock->onHand($saleItem->item, $place);

            if (bccomp($available, '0', 4) > 0) {
                $menu[] = ['sale_item' => $saleItem, 'available' => $available];
            }
        }

        usort($menu, fn (array $a, array $b): int => strcmp(
            (string) $a['sale_item']->item->translate('name'),
            (string) $b['sale_item']->item->translate('name'),
        ));

        return $menu;
    }
}
