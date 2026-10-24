<?php

namespace Add\Inventory\Assets\Models;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Database\Factories\AssetFactory;
use Add\Inventory\Support\Casts\DateOnly;
use Add\Inventory\Support\Casts\PlainDecimal;
use Add\Inventory\Support\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One physical thing of an asset item: the projector in room 3, laptop
 * AST-000042. Its location, custody and status change only through
 * AssetService and MaintenanceService, each leaving its trail.
 */
class Asset extends Model
{
    use HasFactory;

    protected $table = 'inv_assets';

    protected $fillable = [
        'item_id',
        'code',
        'qr_token',
        'serial_number',
        'status',
        'location_type',
        'location_id',
        'purchased_on',
        'purchase_cost',
        'currency',
        'supplier',
        'useful_life_months',
        'salvage_value',
        'warranty_until',
        'warranty_alerted_at',
        'disposed_on',
        'disposal_method',
        'disposal_proceeds',
        'disposal_note',
        'notes',
    ];

    protected $casts = [
        'item_id' => 'integer',
        'status' => AssetStatus::class,
        'location_id' => 'integer',
        'purchased_on' => DateOnly::class,
        'purchase_cost' => PlainDecimal::class.':2',
        'useful_life_months' => 'integer',
        'salvage_value' => PlainDecimal::class.':2',
        'warranty_until' => DateOnly::class,
        'warranty_alerted_at' => 'datetime',
        'disposed_on' => DateOnly::class,
        'disposal_method' => DisposalMethod::class,
        'disposal_proceeds' => PlainDecimal::class.':2',
    ];

    protected $hidden = [
        'qr_token',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class)->orderBy('moved_at')->orderBy('id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class)->orderBy('assigned_at')->orderBy('id');
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(AssetAssignment::class)->whereNull('returned_at');
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class)->orderByDesc('opened_at')->orderByDesc('id');
    }

    public function openMaintenance(): HasOne
    {
        return $this->hasOne(MaintenanceRecord::class)->whereNull('closed_at');
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }

    public function location(): Place
    {
        return new Place($this->location_type, $this->location_id);
    }

    public function isDisposed(): bool
    {
        return $this->status === AssetStatus::Disposed;
    }

    /**
     * Assets kept anywhere among `$places`.
     *
     * @param  list<Place>  $places
     */
    public function scopeAt(Builder $query, array $places): Builder
    {
        return $query->where(function (Builder $query) use ($places) {
            foreach (collect($places)->groupBy('type') as $type => $group) {
                $query->orWhere(fn (Builder $q) => $q->where('location_type', $type)->whereIn('location_id', $group->pluck('id')));
            }

            if ($places === []) {
                $query->whereRaw('1 = 0');
            }
        });
    }

    protected static function newFactory(): AssetFactory
    {
        return AssetFactory::new();
    }
}
