<?php

namespace App\Domain\Membership\Models;

use Add\Inventory\Catalog\Models\Item;
use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The admin-manageable catalog of natural-unit resources a plan can grant a
 * quota of (e.g. "Printing" / unit "page", "Internet" / unit "hour") —
 * docs/decisions/resource-quota-design.md. Not to be confused with
 * the inventory package's items. A type may name the stock it uses up
 * (`inventory_item_id`, `stock_units_per_unit` of it per unit consumed):
 * reception recording printing then issues the paper.
 */
class ResourceType extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'slug',
        'name',
        'unit_label',
        'icon',
        'inventory_item_id',
        'stock_units_per_unit',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'unit_label' => 'array',
            'is_active' => 'boolean',
            'order' => 'integer',
            'inventory_item_id' => 'integer',
            'stock_units_per_unit' => 'decimal:3',
        ];
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'inventory_item_id');
    }

    public function planResourceGrants(): HasMany
    {
        return $this->hasMany(PlanResourceGrant::class);
    }
}
