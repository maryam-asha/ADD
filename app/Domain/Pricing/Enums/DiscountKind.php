<?php

namespace App\Domain\Pricing\Enums;

/**
 * What a row of the discount ledger records
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md):
 * a reduction — the automatic offer, the member's plan discount, a typed
 * coupon — or a plan's built-in duration/volume saving, which changes no
 * price and is reported apart.
 */
enum DiscountKind: string
{
    case Offer = 'offer';
    case PlanDiscount = 'plan_discount';
    case Coupon = 'coupon';
    case DurationSaving = 'duration_saving';

    /** @return list<self> the kinds that took something off a price */
    public static function reductions(): array
    {
        return [self::Offer, self::PlanDiscount, self::Coupon];
    }
}
