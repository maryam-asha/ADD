<?php

namespace App\Domain\Pricing\Enums;

use App\Domain\Finance\Enums\PaymentPurpose;

/**
 * What a price is being composed for — and so what a coupon may target
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 *
 * A wallet top-up has no case on purpose: a coupon on a top-up would hand
 * out points rather than take something off a price. A booking extension
 * has none either — it is priced at the booking's locked terms, never with
 * a coupon of its own.
 */
enum PricingPurpose: string
{
    case Booking = 'booking';
    case CoworkingSession = 'coworking_session';
    case PlanPurchase = 'plan_purchase';
    case EventRegistration = 'event_registration';

    public static function fromPaymentPurpose(PaymentPurpose $purpose): ?self
    {
        return match ($purpose) {
            PaymentPurpose::Booking => self::Booking,
            PaymentPurpose::CoworkingSession => self::CoworkingSession,
            PaymentPurpose::PlanPurchase => self::PlanPurchase,
            PaymentPurpose::EventRegistration => self::EventRegistration,
            default => null,
        };
    }
}
