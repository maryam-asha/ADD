<?php

namespace App\Domain\Foundation\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fixed address for service-request QR codes inside a Co-Space — never a
 * booking unit (PRD decision #4). `space_id` is not DB-restricted to
 * co_space; enforce that at the Form Request that creates these rows.
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
        'label',
    ];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }
}
