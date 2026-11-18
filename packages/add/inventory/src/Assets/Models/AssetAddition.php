<?php

namespace Add\Inventory\Assets\Models;

use Add\Inventory\Support\Casts\DateOnly;
use Add\Inventory\Support\Casts\PlainDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A capital addition: money spent on an asset that raises its cost (an
 * upgrade, a rebuild), in the asset's currency, depreciated on a straight
 * line over what is left of the asset's life from `added_on`.
 *
 * @property int $id
 * @property int $asset_id
 * @property CarbonImmutable $added_on
 * @property string $amount
 * @property string $description
 * @property string|null $reference
 * @property list<string>|null $attachments
 * @property int|null $actor_id
 */
class AssetAddition extends Model
{
    protected $table = 'inv_asset_additions';

    protected $fillable = [
        'asset_id',
        'added_on',
        'amount',
        'description',
        'reference',
        'attachments',
        'actor_id',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'added_on' => DateOnly::class,
        'amount' => PlainDecimal::class.':2',
        'attachments' => 'array',
        'actor_id' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
