<?php

namespace App\Domain\Booking\Models;

use App\Domain\Foundation\Models\Space;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Derived per-slot occupancy. See the table's migration for why this is a
 * read accelerator rather than the capacity authority.
 *
 * Never write this model directly — CapacitySlotSynchronizer owns every
 * mutation, so the increment/decrement always happens inside the same
 * locked transaction as the booking that caused it.
 */
class SpaceCapacitySlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'space_id',
        'slot_start',
        'slot_end',
        'booked_count',
        'capacity_ceiling',
    ];

    protected function casts(): array
    {
        return [
            'slot_start' => 'datetime',
            'slot_end' => 'datetime',
            'booked_count' => 'integer',
            'capacity_ceiling' => 'integer',
        ];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * Slots whose own span intersects the given window. Half-open on both
     * ends, matching Booking::scopeOverlapping — a slot that merely touches
     * the boundary is not inside the window.
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('slot_start', '<', $end)->where('slot_end', '>', $start);
    }

    public function isFull(): bool
    {
        return $this->booked_count >= $this->capacity_ceiling;
    }
}
