<?php

namespace Add\Inventory\Stock\Models;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Enums\StockMovementType;
use Add\Inventory\Support\Casts\PlainDecimal;
use Add\Inventory\Support\Concerns\AppendOnly;
use Add\Inventory\Support\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to the quantity of an item at a location — the ledger stock
 * is counted from. Written only by StockService, never changed afterwards.
 */
class StockMovement extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'inv_stock_movements';

    protected $fillable = [
        'item_id',
        'location_type',
        'location_id',
        'type',
        'quantity',
        'unit_cost',
        'quantity_after',
        'average_cost_after',
        'transfer_id',
        'reason',
        'source_type',
        'source_id',
        'note',
        'actor_id',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'location_id' => 'integer',
        'type' => StockMovementType::class,
        'quantity' => PlainDecimal::class.':3',
        'unit_cost' => PlainDecimal::class.':4',
        'quantity_after' => PlainDecimal::class.':3',
        'average_cost_after' => PlainDecimal::class.':4',
        'reason' => IssueReason::class,
        'source_id' => 'integer',
        'actor_id' => 'integer',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): Place
    {
        return new Place($this->location_type, $this->location_id);
    }
}
