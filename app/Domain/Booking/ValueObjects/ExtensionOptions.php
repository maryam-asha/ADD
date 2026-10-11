<?php

namespace App\Domain\Booking\ValueObjects;

use App\Domain\Booking\Enums\ExtensionBlockReason;
use Carbon\CarbonImmutable;

/**
 * What a booking can be extended by right now: each legal length up to the
 * latest end, priced as the extension would charge it — or, when there is
 * none, why (BookingExtensionPlanner::options()).
 */
final readonly class ExtensionOptions
{
    /**
     * @param  list<array{minutes: int, end_at: CarbonImmutable, points: int}>  $options
     */
    public function __construct(
        public ?ExtensionBlockReason $reason,
        public CarbonImmutable $currentEnd,
        public CarbonImmutable $latestEnd,
        public array $options,
    ) {}

    public function canExtend(): bool
    {
        return $this->reason === null;
    }
}
