<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Exceptions\CouponRejectedException;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\ValueObjects\PriceQuote;
use App\Domain\Pricing\ValueObjects\PricingTarget;
use App\Support\Enums\EventPaymentState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A coupon given when paying for something already owed — an unpaid
 * booking, a closed co-working session, a registration. It lowers what the
 * record owes for good and is filed on it, so a payment that fails can be
 * tried again at the same price, and cancelling the record gives the use
 * back (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 *
 * Refused while a payment for the record is under way (a card in flight
 * would settle at the old amount), once anything was paid toward it, and
 * when it already carries a coupon.
 */
class CouponApplication
{
    public function __construct(
        private readonly CouponLedger $ledger,
        private readonly PriceQuoteService $quotes,
        private readonly PointConversionService $points,
    ) {}

    /**
     * @throws CouponRejectedException
     */
    public function apply(Booking|WalkinSession|EventAttendee $payable, User $member, Coupon $coupon): PriceQuote
    {
        return DB::transaction(function () use ($payable, $member, $coupon) {
            // The coupon's row first (CouponLedger), then the record's.
            $coupon = $this->ledger->lockForRedemption($coupon);

            /** @var Booking|WalkinSession|EventAttendee $locked */
            $locked = $payable::query()->whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

            $quote = $this->preview($locked, $member, $coupon);

            $attributes = ['price_points' => $quote->finalPoints, 'list_price_points' => $quote->listPoints];

            // A booking's and a session's money record moves with its price.
            if (! $locked instanceof EventAttendee) {
                $attributes['amount_owed'] = $this->points->pointsToUsd($quote->finalPoints);
            }

            $locked->forceFill($attributes)->save();
            $this->ledger->record($coupon, $member, $locked, $this->target($locked)->purpose, $quote->couponBasePoints, $quote->couponPoints);

            $payable->setRawAttributes($locked->getAttributes(), true);

            return $quote;
        });
    }

    /**
     * What applying the coupon would leave the record owing — the same
     * checks and price apply() writes, writing nothing.
     *
     * @throws CouponRejectedException
     */
    public function preview(Booking|WalkinSession|EventAttendee $record, User $member, Coupon $coupon): PriceQuote
    {
        $this->assertTakesACoupon($record);

        $price = (int) $record->price_points;

        return $this->quotes->forCouponOn((int) ($record->list_price_points ?? $price), $price, $coupon, $member, $this->target($record));
    }

    /**
     * @throws CouponRejectedException
     */
    private function assertTakesACoupon(Booking|WalkinSession|EventAttendee $record): void
    {
        if ($this->ledger->forRedeemable($record) !== null) {
            throw new CouponRejectedException(CouponRejection::AlreadyApplied);
        }

        if (Payment::query()->forPayable($record)->where('status', PaymentStatus::Pending)->exists()) {
            throw new CouponRejectedException(CouponRejection::PaymentInProgress);
        }

        $owing = match (true) {
            $record instanceof Booking => $record->payment_state === PaymentState::Unpaid && $record->status->isPayable() && $record->pointsPaid() === 0,
            $record instanceof WalkinSession => $record->payment_state === PaymentState::Unpaid && $record->checked_out_at !== null,
            $record instanceof EventAttendee => $record->payment_state === EventPaymentState::Unpaid,
        };

        if (! $owing || (int) $record->price_points <= 0) {
            throw new CouponRejectedException(CouponRejection::NotApplicable);
        }
    }

    private function target(Model $record): PricingTarget
    {
        return match (true) {
            $record instanceof Booking => PricingTarget::forSpace(PricingPurpose::Booking, $record->space),
            $record instanceof WalkinSession => PricingTarget::forSpace(PricingPurpose::CoworkingSession, $record->space),
            $record instanceof EventAttendee => PricingTarget::forEvent($record->event),
            default => throw new InvalidArgumentException('A coupon cannot be applied to '.$record::class.'.'),
        };
    }
}
