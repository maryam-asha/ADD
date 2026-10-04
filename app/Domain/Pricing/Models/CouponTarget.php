<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\PricingPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One thing a coupon applies to: a purpose, and at most one item of it — a
 * plan for a plan purchase, a space or a space type for time in a space, an
 * event for a registration. Mirrors PlanDiscount's space/space-type guard.
 */
class CouponTarget extends Model
{
    protected $fillable = [
        'coupon_id',
        'purpose',
        'plan_id',
        'space_id',
        'space_type',
        'event_id',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => PricingPurpose::class,
            'space_type' => SpaceType::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $target) {
            $items = array_keys(array_filter([
                'plan_id' => $target->plan_id,
                'space_id' => $target->space_id,
                'space_type' => $target->space_type,
                'event_id' => $target->event_id,
            ], fn ($value) => $value !== null));

            if (count($items) > 1) {
                throw new InvalidArgumentException('A coupon target names at most one item.');
            }

            $allowed = match ($target->purpose) {
                PricingPurpose::PlanPurchase => ['plan_id'],
                PricingPurpose::Booking, PricingPurpose::CoworkingSession => ['space_id', 'space_type'],
                PricingPurpose::EventRegistration => ['event_id'],
            };

            if ($items !== [] && ! in_array($items[0], $allowed, true)) {
                throw new InvalidArgumentException("A {$target->purpose->value} target cannot name {$items[0]}.");
            }
        });
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
