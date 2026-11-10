<?php

namespace App\Domain\Kitchen\Models;

use Add\Inventory\Catalog\Models\Item;
use App\Domain\Retail\Models\SaleItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of a kitchen order, at the price it had when the order was sent.
 */
class KitchenOrderLine extends Model
{
    protected $fillable = [
        'kitchen_order_id',
        'sale_item_id',
        'inventory_item_id',
        'quantity',
        'unit_price_points',
        'line_points',
    ];

    protected function casts(): array
    {
        return [
            'kitchen_order_id' => 'integer',
            'sale_item_id' => 'integer',
            'inventory_item_id' => 'integer',
            'quantity' => 'integer',
            'unit_price_points' => 'integer',
            'line_points' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(KitchenOrder::class, 'kitchen_order_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'inventory_item_id');
    }
}
