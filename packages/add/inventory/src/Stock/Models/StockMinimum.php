<?php

namespace Add\Inventory\Stock\Models;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Support\Casts\PlainDecimal;
use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The least of an item a warehouse should hold. StockService compares the
 * balance with it after each movement and warns once per dip.
 */
class StockMinimum extends Model
{
    protected $table = 'inv_stock_minimums';

    protected $fillable = ['warehouse_id', 'item_id', 'minimum', 'alerted_at'];

    protected $casts = [
        'warehouse_id' => 'integer',
        'item_id' => 'integer',
        'minimum' => PlainDecimal::class.':3',
        'alerted_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
