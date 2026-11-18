<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use Carbon\CarbonImmutable;

/**
 * How much of an open day a space sold — the one definition of occupancy,
 * read by the operations calendar and the dashboard's spaces section alike,
 * so a room never reads 60% full on one screen and 40% on the other.
 */
final class OccupancyMeter
{
    /**
     * Booked minutes are clipped to the day's open periods, so a booking
     * that runs to closing time does not report more minutes than the day
     * actually has, and a closed day reports 0% rather than dividing by
     * zero. A space that admits several concurrent bookings is measured
     * against its ceiling, so a hot desk with 20 seats is not "500% full".
     *
     * @param  iterable<Booking>  $bookings  the bookings that touch the day
     * @param  array<int, array{open_time: string, close_time: string}>  $periods
     * @param  CarbonImmutable  $date  the day, in the branch's local timezone
     * @return array{booked_minutes: int, open_minutes: int, percent: int}
     */
    public function day(iterable $bookings, array $periods, CarbonImmutable $date, int $ceiling): array
    {
        $openMinutes = 0;

        foreach ($periods as $period) {
            $open = CarbonImmutable::parse($date->format('Y-m-d').' '.$period['open_time'], $date->timezone);
            $close = CarbonImmutable::parse($date->format('Y-m-d').' '.$period['close_time'], $date->timezone);
            $openMinutes += (int) $open->diffInMinutes($close);
        }

        $bookedMinutes = 0;

        foreach ($bookings as $booking) {
            foreach ($periods as $period) {
                $open = CarbonImmutable::parse($date->format('Y-m-d').' '.$period['open_time'], $date->timezone)->utc();
                $close = CarbonImmutable::parse($date->format('Y-m-d').' '.$period['close_time'], $date->timezone)->utc();

                $start = $booking->start_at->greaterThan($open) ? $booking->start_at : $open;
                $end = $booking->end_at->lessThan($close) ? $booking->end_at : $close;

                if ($start->lessThan($end)) {
                    $bookedMinutes += (int) $start->diffInMinutes($end);
                }
            }
        }

        $capacityMinutes = $openMinutes * max(1, $ceiling);

        return [
            'booked_minutes' => $bookedMinutes,
            'open_minutes' => $openMinutes,
            'percent' => $capacityMinutes === 0
                ? 0
                : (int) min(100, round($bookedMinutes / $capacityMinutes * 100)),
        ];
    }
}
