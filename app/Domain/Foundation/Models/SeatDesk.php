<?php

namespace App\Domain\Foundation\Models;

use App\Domain\Foundation\Enums\SpaceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One seat of a co_space, with the `code` its QR carries (unique within the
 * branch) and a display `label`. A co_space's `capacity` is the number of its
 * desks, recounted here whenever one is added, moved or removed — so the
 * branch's co-working pool counts desks (docs/decisions/coworking-seat-pool.md).
 *
 * A Dedicated Desk subscription is tied to specific desks through
 * `membership_seat_desk`, owned by the Membership domain
 * (DedicatedDeskService) so Foundation depends on nothing above it;
 * walk-ins and co_space bookings never pick a desk.
 *
 * `qr_point_id` has no relation yet — `qr_points` doesn't exist until
 * Phase 7, which wires up both the FK constraint and the relation.
 */
class SeatDesk extends Model
{
    use HasFactory;

    // Eloquent's convention would guess "seat_desks"; the table is
    // "seats_desks" (ERD v2.0 naming).
    protected $table = 'seats_desks';

    protected $fillable = [
        'space_id',
        'qr_point_id',
        'code',
        'label',
    ];

    protected static function booted(): void
    {
        static::saved(function (self $desk) {
            self::recountCapacity($desk->space_id);

            if ($desk->wasChanged('space_id')) {
                self::recountCapacity($desk->getOriginal('space_id'));
            }
        });

        static::deleted(fn (self $desk) => self::recountCapacity($desk->space_id));
    }

    /** A co_space's seats are its desks; any other space keeps its own number. */
    public static function recountCapacity(?int $spaceId): void
    {
        if ($spaceId === null) {
            return;
        }

        Space::query()
            ->whereKey($spaceId)
            ->where('space_type', SpaceType::CoSpace->value)
            ->update(['capacity' => self::query()->where('space_id', $spaceId)->count()]);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
