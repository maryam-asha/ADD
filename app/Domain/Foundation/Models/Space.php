<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id',
        'zone_id',
        'space_type',
        'allocation_model',
        'is_lockable',
        'capacity',
        'hourly_rate',
        'pricing_currency',
        'cancellation_window_minutes',
        'status',
        'status_reason',
        'status_from',
        'status_until',
    ];

    protected function casts(): array
    {
        return [
            'space_type' => SpaceType::class,
            'allocation_model' => AllocationModel::class,
            'is_lockable' => 'boolean',
            'hourly_rate' => 'decimal:2',
            'status' => OperationalStatus::class,
            'status_from' => 'datetime',
            'status_until' => 'datetime',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function seatsDesks(): HasMany
    {
        return $this->hasMany(SeatDesk::class);
    }

    /**
     * A non-active space disappears from search/booking results immediately,
     * regardless of calendar availability (PRD §5.6) — this is the read-side
     * building block Phase 5's actual availability query is built on top of,
     * not that query itself.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OperationalStatus::Active);
    }
}
