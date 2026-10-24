<?php

namespace Add\Inventory\Assets\Models;

use Add\Inventory\Assets\Enums\MaintenanceType;
use Add\Inventory\Support\Casts\PlainDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One repair or servicing of an asset. While it is open the asset is
 * `in_maintenance`.
 */
class MaintenanceRecord extends Model
{
    protected $table = 'inv_maintenance_records';

    protected $fillable = [
        'asset_id',
        'schedule_id',
        'type',
        'description',
        'vendor',
        'opened_at',
        'closed_at',
        'cost',
        'currency',
        'resolution',
        'opened_by',
        'closed_by',
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'schedule_id' => 'integer',
        'type' => MaintenanceType::class,
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'cost' => PlainDecimal::class.':2',
        'opened_by' => 'integer',
        'closed_by' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(MaintenanceSchedule::class, 'schedule_id');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
