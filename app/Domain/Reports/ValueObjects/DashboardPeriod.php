<?php

namespace App\Domain\Reports\ValueObjects;

use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;

/**
 * The window a dashboard section reports on: local calendar days, `from`
 * and `to` both inclusive, held as the UTC instants [start, end) stored
 * timestamps are compared against. Defaults to this month so far.
 */
final class DashboardPeriod
{
    private function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        private readonly LocalTime $time,
    ) {}

    public static function between(?string $from, ?string $to, LocalTime $time): self
    {
        $today = $time->toLocal(now())->toDateString();
        $to ??= max($today, $from ?? $today);
        $from ??= CarbonImmutable::parse($to, 'UTC')->startOfMonth()->toDateString();

        return self::of($from, $to, $time);
    }

    /** As many days again, ending the day before this one starts. */
    public function previous(): self
    {
        $from = CarbonImmutable::parse($this->from, 'UTC');
        $days = (int) $from->diffInDays(CarbonImmutable::parse($this->to, 'UTC')) + 1;

        return self::of($from->subDays($days)->toDateString(), $from->subDay()->toDateString(), $this->time);
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->from, 'to' => $this->to];
    }

    private static function of(string $from, string $to, LocalTime $time): self
    {
        $dayAfter = CarbonImmutable::parse($to, 'UTC')->addDay()->toDateString();

        return new self(
            $from,
            $to,
            CarbonImmutable::instance($time->toUtc("{$from} 00:00")),
            CarbonImmutable::instance($time->toUtc("{$dayAfter} 00:00")),
            $time,
        );
    }
}
