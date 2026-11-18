<?php

namespace Add\Inventory\Stock\Models;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Support\Casts\PlainDecimal;
use Add\Inventory\Support\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The running total of the ledger for one item at one location, and its
 * weighted average cost. Kept by StockService in the same transaction as
 * the movement that changes it, under a row lock; never written elsewhere.
 */
class StockBalance extends Model
{
    public const CREATED_AT = null;

    protected $table = 'inv_stock_balances';

    protected $fillable = [
        'item_id',
        'location_type',
        'location_id',
        'quantity',
        'average_cost',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'location_id' => 'integer',
        'quantity' => PlainDecimal::class.':3',
        'average_cost' => PlainDecimal::class.':4',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function location(): Place
    {
        return new Place($this->location_type, $this->location_id);
    }

    /**
     * Balances anywhere among `$places`.
     *
     * @param  list<Place>  $places
     */
    public function scopeAt(Builder $query, array $places): Builder
    {
        return $query->where(function (Builder $query) use ($places) {
            foreach (collect($places)->groupBy('type') as $type => $group) {
                $query->orWhere(fn (Builder $q) => $q->where('location_type', $type)->whereIn('location_id', $group->pluck('id')));
            }

            if ($places === []) {
                $query->whereRaw('1 = 0');
            }
        });
    }
}
