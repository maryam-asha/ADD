<?php

namespace App\Domain\Pricing\ValueObjects;

use App\Domain\Experience\Models\Event;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Models\Plan;
use App\Domain\Pricing\Enums\PricingPurpose;

/**
 * What is being bought, as a coupon target sees it: the purpose, and the
 * item a target may name — the plan, the space and its type, the event.
 */
final class PricingTarget
{
    public function __construct(
        public readonly PricingPurpose $purpose,
        public readonly ?int $planId = null,
        public readonly ?int $spaceId = null,
        public readonly ?SpaceType $spaceType = null,
        public readonly ?int $eventId = null,
    ) {}

    /** Whether a plan, a space or an event is named, not just a purpose. */
    public function namesItem(): bool
    {
        return $this->planId !== null || $this->spaceId !== null || $this->spaceType !== null || $this->eventId !== null;
    }

    /** Time in a space: a booking, or a co-working session. */
    public static function forSpace(PricingPurpose $purpose, Space $space): self
    {
        return new self($purpose, spaceId: $space->id, spaceType: $space->space_type);
    }

    public static function forPlan(Plan $plan): self
    {
        return new self(PricingPurpose::PlanPurchase, planId: $plan->id);
    }

    public static function forEvent(Event $event): self
    {
        return new self(PricingPurpose::EventRegistration, eventId: $event->id);
    }
}
