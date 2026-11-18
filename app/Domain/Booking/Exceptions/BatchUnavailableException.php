<?php

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * One or more windows in a multi-date booking could not be taken.
 *
 * Carries a per-window failure list rather than a single message: the
 * member picked four dates and needs to know which one is the problem,
 * not that "something" was unavailable.
 *
 * Throwing this rolls the whole batch back — see
 * BookingBatchCreationService for why partial success is not offered.
 */
class BatchUnavailableException extends RuntimeException
{
    /**
     * @param  list<array{index: int, start_at: string, reason: string, message_key: string}>  $failures
     */
    public function __construct(public readonly array $failures)
    {
        parent::__construct('One or more requested windows are unavailable.');
    }
}
