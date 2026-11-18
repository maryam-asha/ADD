<?php

namespace App\Domain\Kitchen\Models;

use Add\Inventory\Catalog\Models\Item;
use App\Domain\Retail\Models\SaleItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of a kitchen order, at the price it had when the order was sent.
 * `quantity` is what is made — fewer than `ordered_quantity`, or 0, when the
 * café accepted the order with less of it.
 */
class KitchenOrderLine extends Model
{
    protected $fillable = [
        'kitchen_order_id',
        'sale_item_id',
        'inventory_item_id',
        'quantity',
        'ordered_quantity',
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
            'ordered_quantity' => 'integer',
            'unit_price_points' => 'integer',
            'line_points' => 'integer',
        ];
    }

    /** What the member ordered; a line from before the column is its quantity. */
    public function orderedQuantity(): int
    {
        return (int) ($this->ordered_quantity ?? $this->quantity);
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
