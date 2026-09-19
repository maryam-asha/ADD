<?php

namespace App\Domain\Booking\ValueObjects;

use App\Domain\Booking\Enums\SlotState;
use Carbon\CarbonImmutable;

/**
 * One chip in the time grid: an instant range plus why it is or is not
 * bookable.
 *
 * Instants are UTC — the presenter converts to local for display, the
 * same division of labour every other endpoint in this app follows
 * (App\Support\Time\LocalTime).
 */
final readonly class SlotAvailability
{
    public function __construct(
        public CarbonImmutable $startAt,
        public CarbonImmutable $endAt,
        public SlotState $state,
    ) {}

    public function isAvailable(): bool
    {
        return $this->state->isBookable();
    }

    /**
     * The lang key for this slot's state, or null when it is bookable.
     */
    public function reasonKey(): ?string
    {
        return $this->state->reasonKey();
    }
}
