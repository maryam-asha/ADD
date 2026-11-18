<?php

namespace Add\Inventory\Assets\Models;

use Add\Inventory\Support\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An asset in someone's custody, from the day it was handed over until the
 * day it came back. At most one open (unreturned) row per asset.
 */
class AssetAssignment extends Model
{
    protected $table = 'inv_asset_assignments';

    protected $fillable = [
        'asset_id',
        'custodian_type',
        'custodian_id',
        'assigned_at',
        'returned_at',
        'assigned_by',
        'returned_by',
        'note',
        'return_note',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'custodian_id' => 'integer',
        'assigned_at' => 'datetime',
        'returned_at' => 'datetime',
        'assigned_by' => 'integer',
        'returned_by' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function custodian(): Place
    {
        return new Place($this->custodian_type, $this->custodian_id);
    }

    public function isOpen(): bool
    {
        return $this->returned_at === null;
    }
}
