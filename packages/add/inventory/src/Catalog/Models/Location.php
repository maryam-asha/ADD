<?php

namespace Add\Inventory\Catalog\Models;

use Add\Inventory\Database\Factories\LocationFactory;
use Add\Inventory\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the package's own places (a warehouse, a room, a shelf), used only
 * while the host binds no LocationResolver of its own.
 */
class Location extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'inv_locations';

    protected $fillable = [
        'parent_id',
        'code',
        'name',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'name' => 'array',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected static function newFactory(): LocationFactory
    {
        return LocationFactory::new();
    }
}
