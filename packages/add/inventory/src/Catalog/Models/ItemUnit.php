<?php

namespace Add\Inventory\Catalog\Models;

use Add\Inventory\Support\Casts\PlainDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pack of an item's main unit: `factor` main units in one `unit`
 * ("1 box = 6 pieces").
 */
class ItemUnit extends Model
{
    protected $table = 'inv_item_units';

    protected $fillable = [
        'item_id',
        'unit_id',
        'factor',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'unit_id' => 'integer',
        'factor' => PlainDecimal::class.':4',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
