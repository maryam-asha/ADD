<?php

namespace App\Domain\Booking\Services;

use App\Domain\Foundation\Models\Space;
use Carbon\CarbonInterface;

/**
 * Shared by SessionClosureService (actual checked-in-to-checked-out
 * duration) and BookingCancellationService (planned start_at-to-end_at
 * duration, for a wallet refund when a booking is cancelled before check-in
 * ever happens). bcmath throughout — DECIMAL(10,2) exclusively, never a
 * float (decision #15).
 */
class AmountCalculator
{
    /**
     * @return array{0: string, 1: ?string} [amount, currency]
     */
    public function forRange(Space $space, CarbonInterface $start, CarbonInterface $end): array
    {
        $seconds = $start->diffInSeconds($end);
        $hours = bcdiv((string) $seconds, '3600', 6);
        $rate = (string) ($space->hourly_rate ?? '0.00');

        return [bcmul($hours, $rate, 2), $space->pricing_currency];
    }
}
