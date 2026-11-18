<?php

namespace App\Domain\Retail\Models;

use Add\Inventory\Catalog\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stock item reception may sell, at its price in points. The inventory
 * package keeps the item and its quantities; the price is ADD's.
 */
class SaleItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_item_id',
        'price_points',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'inventory_item_id' => 'integer',
            'price_points' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'inventory_item_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
