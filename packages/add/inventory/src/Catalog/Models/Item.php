<?php

namespace Add\Inventory\Catalog\Models;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Catalog\Enums\ItemKind;
use Add\Inventory\Database\Factories\ItemFactory;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of thing in the catalog ("27-inch monitor", "A4 paper"). Whether
 * it is an asset or stock comes from its category, never stored twice.
 */
class Item extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'inv_items';

    protected $fillable = [
        'category_id',
        'unit_id',
        'sku',
        'name',
        'description',
        'properties',
        'is_active',
    ];

    /**
     * The column defaults, so a model just created reads them too.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'category_id' => 'integer',
        'unit_id' => 'integer',
        'name' => 'array',
        'properties' => 'array',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function kind(): ItemKind
    {
        return $this->category->kind;
    }

    public function isStock(): bool
    {
        return $this->kind() === ItemKind::Stock;
    }

    public function isAsset(): bool
    {
        return $this->kind() === ItemKind::Asset;
    }

    /**
     * Whether anything has been recorded against it: past that point it
     * keeps its kind and cannot be deleted.
     */
    public function hasHistory(): bool
    {
        return $this->assets()->exists() || $this->stockMovements()->exists();
    }

    public function scopeOfKind(Builder $query, ItemKind $kind): Builder
    {
        return $query->whereHas('category', fn (Builder $category) => $category->where('kind', $kind));
    }

    protected static function newFactory(): ItemFactory
    {
        return ItemFactory::new();
    }
}
