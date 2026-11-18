<?php

namespace App\Domain\Booking\ValueObjects;

use App\Domain\Booking\Enums\DayState;
use Carbon\CarbonImmutable;

/**
 * One cell in the month grid.
 *
 * `closureReason` is the only way to tell an explicit holiday from a
 * weekday that simply has no schedule: BusinessHoursService::periodsFor()
 * returns an empty array for both, and says so in its own docblock. A
 * non-null reason here means a BusinessHourException row carried one.
 */
final readonly class DayAvailability
{
    /**
     * @param  array<int, array{open_time: string, close_time: string}>  $periods
     */
    public function __construct(
        public CarbonImmutable $date,
        public DayState $state,
        public array $periods,
        public ?string $closureReason,
        public int $availableSlotCount,
        public int $totalSlotCount,
    ) {}

    public function isSelectable(): bool
    {
        return $this->state->isSelectable();
    }

    public function isClosed(): bool
    {
        return $this->state === DayState::Closed;
    }
}
