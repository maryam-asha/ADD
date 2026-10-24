<?php

namespace Add\Inventory\Catalog\Models;

use Add\Inventory\Database\Factories\UnitFactory;
use Add\Inventory\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an item is counted in (a piece, a ream, a kilogram). A unit that
 * does not allow fractions takes whole quantities only.
 */
class Unit extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'inv_units';

    protected $fillable = [
        'code',
        'name',
        'allows_fractions',
    ];

    protected $casts = [
        'name' => 'array',
        'allows_fractions' => 'boolean',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    protected static function newFactory(): UnitFactory
    {
        return UnitFactory::new();
    }
}
