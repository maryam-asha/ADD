<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\CouponRejection;
use App\Domain\Pricing\Enums\CouponSource;
use App\Domain\Pricing\Enums\PricingPurpose;
use Tests\TestCase;

/**
 * docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md
 */
class EnumsTest extends TestCase
{
    /**
     * Everything a coupon can be spent on. A wallet top-up is deliberately
     * absent: a coupon on a top-up would be free money, not a discount.
     */
    public function test_pricing_purpose_cases(): void
    {
        $this->assertSame(
            ['booking', 'coworking_session', 'plan_purchase', 'event_registration'],
            array_column(PricingPurpose::cases(), 'value'),
        );
    }

    public function test_a_payment_purpose_maps_to_its_pricing_purpose(): void
    {
        $this->assertSame(PricingPurpose::Booking, PricingPurpose::fromPaymentPurpose(PaymentPurpose::Booking));
        $this->assertSame(PricingPurpose::CoworkingSession, PricingPurpose::fromPaymentPurpose(PaymentPurpose::CoworkingSession));
        $this->assertSame(PricingPurpose::PlanPurchase, PricingPurpose::fromPaymentPurpose(PaymentPurpose::PlanPurchase));
        $this->assertSame(PricingPurpose::EventRegistration, PricingPurpose::fromPaymentPurpose(PaymentPurpose::EventRegistration));
        $this->assertNull(PricingPurpose::fromPaymentPurpose(PaymentPurpose::WalletTopUp));
        $this->assertNull(PricingPurpose::fromPaymentPurpose(PaymentPurpose::BookingExtension));
    }

    public function test_coupon_discount_type_cases(): void
    {
        $this->assertSame(['percentage', 'amount_off'], array_column(CouponDiscountType::cases(), 'value'));
    }

    /** Phase 1 issues coupons from the dashboard only; gift and referral come later. */
    public function test_coupon_source_cases(): void
    {
        $this->assertSame(['admin'], array_column(CouponSource::cases(), 'value'));
    }

    public function test_every_coupon_rejection_names_its_translation_key(): void
    {
        foreach (CouponRejection::cases() as $rejection) {
            $this->assertSame('api.coupon.'.$rejection->value, $rejection->messageKey());
        }
    }

    /**
     * A payment a coupon was locked on counts against the coupon while it
     * can still end up paid; once it failed, expired, was cancelled or
     * reversed, the use comes back.
     */
    public function test_the_payment_statuses_that_hold_a_coupon_use(): void
    {
        $this->assertSame(
            [PaymentStatus::Pending, PaymentStatus::Accepted, PaymentStatus::NeedsReview],
            PaymentStatus::holdsRedemption(),
        );
    }
}
