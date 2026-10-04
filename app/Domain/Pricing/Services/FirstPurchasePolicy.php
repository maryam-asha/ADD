<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Models\CouponRedemption;
use App\Support\Enums\EventPaymentState;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a member is still "new" for a new-members-only coupon: they have
 * not yet paid for anything, and have not already used a welcome coupon
 * that still counts (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md, D4).
 *
 * Paying is read two ways, because records paid before payment rows
 * existed — or settled by hand — carry it only on themselves: a settled,
 * unrefunded payment of more than 0 points for anything but a top-up, or a
 * booking, walk-in or registration that is paid at a price above 0. A
 * top-up buys nothing, and a membership staff granted was not bought.
 */
class FirstPurchasePolicy
{
    public function isFirstPurchase(User $member): bool
    {
        return ! $this->hasPaidForSomething($member) && ! $this->hasUsedAWelcome($member);
    }

    private function hasPaidForSomething(User $member): bool
    {
        $paid = Payment::query()
            ->where('user_id', $member->id)
            ->where('status', PaymentStatus::Accepted)
            ->whereNotNull('settled_at')
            ->whereNull('refunded_at')
            ->where('purpose', '!=', PaymentPurpose::WalletTopUp)
            ->where('amount_points', '>', 0)
            ->exists();

        if ($paid) {
            return true;
        }

        $paidAbove = fn (Builder $query, mixed $paidState) => $query
            ->where('user_id', $member->id)
            ->where('payment_state', $paidState)
            ->where('price_points', '>', 0)
            ->exists();

        return $paidAbove(Booking::query(), PaymentState::Paid)
            || $paidAbove(WalkinSession::query(), PaymentState::Paid)
            || $paidAbove(EventAttendee::query(), EventPaymentState::Paid);
    }

    private function hasUsedAWelcome(User $member): bool
    {
        return CouponRedemption::query()
            ->live()
            ->where('user_id', $member->id)
            ->whereHas('coupon', fn (Builder $coupon) => $coupon->where('new_members_only', true))
            ->exists();
    }
}
