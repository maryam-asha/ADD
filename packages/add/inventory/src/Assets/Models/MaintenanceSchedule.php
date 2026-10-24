<?php

namespace Add\Inventory\Assets\Models;

use Add\Inventory\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Routine servicing every `interval_months`. Closing a maintenance record
 * opened from it moves `next_due_on` on from the day the work was done.
 */
class MaintenanceSchedule extends Model
{
    protected $table = 'inv_maintenance_schedules';

    protected $fillable = [
        'asset_id',
        'title',
        'interval_months',
        'next_due_on',
        'alerted_for',
        'is_active',
    ];

    /**
     * The column defaults, so a model just created reads them too.
     */
    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'asset_id' => 'integer',
        'interval_months' => 'integer',
        'next_due_on' => DateOnly::class,
        'alerted_for' => DateOnly::class,
        'is_active' => 'boolean',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
