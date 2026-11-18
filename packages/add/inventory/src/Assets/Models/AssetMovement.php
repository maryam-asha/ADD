<?php

namespace Add\Inventory\Assets\Models;

use Add\Inventory\Support\Concerns\AppendOnly;
use Add\Inventory\Support\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An asset going from one place to another. The first row of every asset
 * is its registration (no `from`).
 */
class AssetMovement extends Model
{
    use AppendOnly;

    public const UPDATED_AT = null;

    protected $table = 'inv_asset_movements';

    protected $fillable = [
        'asset_id',
        'from_type',
        'from_id',
        'to_type',
        'to_id',
        'note',
        'actor_id',
        'moved_at',
    ];

    protected $casts = [
        'from_id' => 'integer',
        'to_id' => 'integer',
        'actor_id' => 'integer',
        'moved_at' => 'datetime',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function from(): ?Place
    {
        return $this->from_type === null ? null : new Place($this->from_type, $this->from_id);
    }

    public function to(): Place
    {
        return new Place($this->to_type, $this->to_id);
    }
}
