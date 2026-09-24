<?php

namespace App\Domain\Membership\Models;

use App\Domain\Booking\Models\Booking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per booking a PlanDiscount was actually applied to — the
 * append-only ledger `usage_limit` is counted against, never a stored
 * counter (docs/decisions/plan-discounts-design.md).
 */
class PlanDiscountRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_discount_id',
        'membership_id',
        'booking_id',
        'points_discounted',
    ];

    protected function casts(): array
    {
        return [
            'points_discounted' => 'integer',
        ];
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(PlanDiscount::class, 'plan_discount_id');
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
