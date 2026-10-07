<?php

namespace Tests\Feature\Pricing\Concerns;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;

/**
 * Automatic offers for tests — what phase 1 wrote as an item's sale
 * columns: a fixed price on one space (an hourly rate, for bookings and
 * co-working sessions alike), one plan or one event, over a window.
 */
trait MakesOffers
{
    /** @param  array<string, mixed>  $attributes */
    protected function offerOnSpace(Space $space, int $rate, ?string $from = null, ?string $to = null, array $attributes = []): Coupon
    {
        return $this->fixedPriceOffer($rate, $from, $to, $attributes, [
            ['purpose' => PricingPurpose::Booking, 'space_id' => $space->id],
            ['purpose' => PricingPurpose::CoworkingSession, 'space_id' => $space->id],
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function offerOnPlan(Plan $plan, int $price, ?string $from = null, ?string $to = null, array $attributes = []): Coupon
    {
        return $this->fixedPriceOffer($price, $from, $to, $attributes, [
            ['purpose' => PricingPurpose::PlanPurchase, 'plan_id' => $plan->id],
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function offerOnEvent(Event $event, int $price, array $attributes = []): Coupon
    {
        return $this->fixedPriceOffer($price, null, null, $attributes, [
            ['purpose' => PricingPurpose::EventRegistration, 'event_id' => $event->id],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $targets
     */
    private function fixedPriceOffer(int $points, ?string $from, ?string $to, array $attributes, array $targets): Coupon
    {
        $offer = Coupon::factory()->automatic()->create(array_merge([
            'name' => ['en' => 'Offer', 'ar' => 'عرض'],
            'discount_type' => CouponDiscountType::FixedPrice,
            'discount_value' => $points,
            'starts_at' => $from,
            'ends_at' => $to,
        ], $attributes));

        foreach ($targets as $target) {
            $offer->targets()->create($target);
        }

        return $offer;
    }
}
