<?php

namespace Tests\Feature\Pricing\Concerns;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use LogicException;

/**
 * Automatic offers for tests — what phase 1 wrote as an item's sale
 * columns: the price one space (an hourly rate, for bookings and
 * co-working sessions alike), one plan or one event comes down to, over a
 * window. Written as the points off that leave the item at that price, so
 * set the item's own price before asking.
 */
trait MakesOffers
{
    /** @param  array<string, mixed>  $attributes */
    protected function offerOnSpace(Space $space, int $rate, ?string $from = null, ?string $to = null, array $attributes = []): Coupon
    {
        return $this->pointsOffOffer((int) $space->hourly_rate_points - $rate, $from, $to, $attributes, [
            ['purpose' => PricingPurpose::Booking, 'space_id' => $space->id],
            ['purpose' => PricingPurpose::CoworkingSession, 'space_id' => $space->id],
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function offerOnPlan(Plan $plan, int $price, ?string $from = null, ?string $to = null, array $attributes = []): Coupon
    {
        return $this->pointsOffOffer((int) $plan->price_points - $price, $from, $to, $attributes, [
            ['purpose' => PricingPurpose::PlanPurchase, 'plan_id' => $plan->id],
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function offerOnEvent(Event $event, int $price, array $attributes = []): Coupon
    {
        return $this->pointsOffOffer((int) $event->price_points - $price, null, null, $attributes, [
            ['purpose' => PricingPurpose::EventRegistration, 'event_id' => $event->id],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $targets
     */
    private function pointsOffOffer(int $points, ?string $from, ?string $to, array $attributes, array $targets): Coupon
    {
        if ($points < 1) {
            throw new LogicException('An offer brings a price down: ask for one below the item\'s own.');
        }

        $offer = Coupon::factory()->automatic()->create(array_merge([
            'name' => ['en' => 'Offer', 'ar' => 'عرض'],
            'discount_type' => CouponDiscountType::AmountOff,
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
