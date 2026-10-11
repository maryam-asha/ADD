<?php

namespace App\Domain\Foundation\Models;

use App\Casts\AsMediaUrlArray;
use App\Concerns\HasTranslations;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Leasing\Models\OfficeLease;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Space extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'building_id',
        'zone_id',
        'name',
        'space_type',
        'allocation_model',
        'is_lockable',
        'capacity',
        'images',
        'hourly_rate_points',
        'cancellation_window_minutes',
        'slot_granularity_minutes',
        'buffer_minutes',
        'requires_approval',
        'allows_extension',
        'status',
        'status_reason',
        'status_from',
        'status_until',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'space_type' => SpaceType::class,
            'allocation_model' => AllocationModel::class,
            'is_lockable' => 'boolean',
            'hourly_rate_points' => 'integer',
            'requires_approval' => 'boolean',
            'allows_extension' => 'boolean',
            'cancellation_window_minutes' => 'integer',
            'status' => OperationalStatus::class,
            'status_from' => 'datetime',
            'status_until' => 'datetime',
            'images' => AsMediaUrlArray::class,
        ];
    }

    /**
     * The title a member sees. A space's own `name` (translated) always wins;
     * the per-`space_type` string only covers spaces created before that
     * column was wired up (or seeded/factory-made without one), so they never
     * regress to a blank title. One definition for the space browser and the
     * booking screens, so one space never reads differently on two of them.
     *
     * Not `title()`: an Eloquent property read of that name would be taken
     * for a relation.
     */
    public function displayTitle(?string $locale = null): string
    {
        $name = $this->translate('name', $locale);

        if (! empty($name)) {
            return $name;
        }

        return match ($this->space_type) {
            SpaceType::CoSpace => 'Hot Desk',
            SpaceType::Room => 'Meeting Room A',
            SpaceType::Business => 'Private Office '.$this->id,
            SpaceType::EventHall => 'Event Hall',
        };
    }

    /**
     * Primary / cover image derived from the first image in the images collection.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->images[0] ?? null;
    }

    /**
     * Alias for image_url as main_image.
     */
    public function getMainImageAttribute(): ?string
    {
        return $this->image_url;
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * Whether the branch already has a co_space other than $exceptId. A
     * branch's co-working seats are the desks of its one co_space, and a
     * retired one no longer counts (docs/decisions/coworking-seat-pool.md).
     */
    public static function branchHasCoSpace(int $branchId, ?int $exceptId = null): bool
    {
        return self::query()
            ->where('space_type', SpaceType::CoSpace->value)
            ->where('status', '!=', OperationalStatus::Retired->value)
            ->whereHas('building', fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function officeLeases(): HasMany
    {
        return $this->hasMany(OfficeLease::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(Resource::class);
    }

    public function amenities(): HasMany
    {
        return $this->hasMany(SpaceAmenity::class);
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
