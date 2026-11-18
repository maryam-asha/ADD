<?php

namespace App\Domain\Booking\Models;

use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room lent to co-working from `starts_at` to `ends_at`: its `seats` join
 * the branch's co-working seat pool, and the room refuses room bookings
 * meanwhile (docs/decisions/coworking-seat-pool.md).
 */
class SpaceCoworkingWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'space_id',
        'starts_at',
        'ends_at',
        'seats',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'seats' => 'integer',
        ];
    }

    /** Half-open, as for bookings: a window ending at 14:00 does not touch one from 14:00. */
    public function scopeOverlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query->where('starts_at', '<', $end)->where('ends_at', '>', $start);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
