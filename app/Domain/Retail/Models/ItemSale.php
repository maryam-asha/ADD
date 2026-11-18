<?php

namespace App\Domain\Retail\Models;

use Add\Inventory\Support\Place;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * What a member bought at the desk. The payable of its payment (purpose
 * item_sale): written and paid in one transaction, so a sale on record is
 * a paid one, and its lines have left stock (ItemSaleFulfiller).
 */
class ItemSale extends Model
{
    public const SOURCE = 'item_sale';

    protected $fillable = [
        'user_id',
        'location_type',
        'location_id',
        'total_points',
        'sold_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'location_id' => 'integer',
            'total_points' => 'integer',
            'sold_by' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ItemSaleLine::class);
    }

    public function payment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable');
    }

    public function location(): Place
    {
        return new Place($this->location_type, $this->location_id);
    }
}
