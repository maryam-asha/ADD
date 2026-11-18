<?php

namespace App\Domain\Retail\Models;

use Add\Inventory\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One item of a sale, at the price it was sold for.
 */
class ItemSaleLine extends Model
{
    protected $fillable = [
        'item_sale_id',
        'inventory_item_id',
        'quantity',
        'unit_price_points',
        'line_points',
    ];

    protected function casts(): array
    {
        return [
            'item_sale_id' => 'integer',
            'inventory_item_id' => 'integer',
            'quantity' => 'integer',
            'unit_price_points' => 'integer',
            'line_points' => 'integer',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ItemSale::class, 'item_sale_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'inventory_item_id');
    }
}
