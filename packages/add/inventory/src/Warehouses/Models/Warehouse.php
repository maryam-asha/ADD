<?php

namespace Add\Inventory\Warehouses\Models;

use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Database\Factories\WarehouseFactory;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Support\Concerns\HasTranslations;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Enums\ItemScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A store where stock is kept (the kitchen's, the café's, an external
 * one). The stock ledger names it as the location `('warehouse', id)`.
 */
class Warehouse extends Model
{
    use HasFactory;
    use HasTranslations;

    /** The location type the stock ledger stores for a warehouse. */
    public const TYPE = 'warehouse';

    protected $table = 'inv_warehouses';

    protected $fillable = [
        'name',
        'code',
        'manager_id',
        'address',
        'location_url',
        'notes',
        'place_type',
        'place_id',
        'item_scope',
        'allows_sales',
        'is_primary',
        'is_active',
    ];

    /**
     * The column defaults, so a model just created reads them too.
     */
    protected $attributes = [
        'item_scope' => 'all',
        'allows_sales' => true,
        'is_primary' => false,
        'is_active' => true,
    ];

    protected $casts = [
        'name' => 'array',
        'manager_id' => 'integer',
        'place_id' => 'integer',
        'item_scope' => ItemScope::class,
        'allows_sales' => 'boolean',
        'is_primary' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'inv_warehouse_items');
    }

    /**
     * What the ledger counts here, item by item.
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'location_id')->where('location_type', self::TYPE);
    }

    public function place(): ?Place
    {
        return $this->place_type === null || $this->place_id === null ? null : new Place($this->place_type, $this->place_id);
    }

    public function asPlace(): Place
    {
        return new Place(self::TYPE, $this->id);
    }

    /**
     * Whether its scope lets it hold the item.
     */
    public function allows(Item $item): bool
    {
        return $this->item_scope === ItemScope::All
            || $this->items()->whereKey($item->id)->exists();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): WarehouseFactory
    {
        return WarehouseFactory::new();
    }
}
