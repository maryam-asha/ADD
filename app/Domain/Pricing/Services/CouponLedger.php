<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Models\CouponRedemption;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The ledger a coupon's limits are counted against: one redemption per
 * price it was locked into, counted while it is live, never a stored
 * counter (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 *
 * Locking protocol: lockForRedemption() is the FIRST lock — and the first
 * read — in any transaction that redeems. Under MySQL's REPEATABLE READ the
 * snapshot is taken at the first plain read, so counting after a later lock
 * could miss a use committed in between and let "the first ten" become
 * eleven; and taking it after Branch/Space would deadlock against a plan
 * purchase. Order: Coupon → Booking → Branch → Space / Event → Payment →
 * Wallet.
 */
class CouponLedger
{
    public function lockForRedemption(Coupon $coupon): Coupon
    {
        return Coupon::query()->whereKey($coupon->getKey())->lockForUpdate()->firstOrFail();
    }

    /** Live uses: of everyone, or of one member. */
    public function liveUsage(Coupon $coupon, ?User $member = null): int
    {
        return $coupon->redemptions()
            ->live()
            ->when($member !== null, fn ($query) => $query->where('user_id', $member->id))
            ->count();
    }

    /**
     * Files a use on the record it took points off, with a copy of the
     * coupon's terms. Called inside the transaction that locked the coupon.
     */
    public function record(Coupon $coupon, User $member, Model $record, PricingPurpose $purpose, int $basePoints, int $pointsDiscounted): CouponRedemption
    {
        return CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'user_id' => $member->id,
            'purpose' => $purpose,
            $this->column($record) => $record->getKey(),
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'max_discount_points' => $coupon->max_discount_points,
            'base_points' => $basePoints,
            'points_discounted' => $pointsDiscounted,
        ]);
    }

    public function forRedeemable(Model $record): ?CouponRedemption
    {
        return CouponRedemption::query()->where($this->column($record), $record->getKey())->first();
    }

    private function column(Model $record): string
    {
        return match (true) {
            $record instanceof Booking => 'booking_id',
            $record instanceof WalkinSession => 'walkin_session_id',
            $record instanceof EventAttendee => 'event_attendee_id',
            $record instanceof Payment => 'payment_id',
            default => throw new InvalidArgumentException('A coupon cannot be redeemed on '.$record::class.'.'),
        };
    }
}
