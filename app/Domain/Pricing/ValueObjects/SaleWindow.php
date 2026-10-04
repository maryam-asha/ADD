<?php

namespace App\Domain\Pricing\ValueObjects;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * An item's sale: a price (a space's hourly rate, or a plan's price) and
 * the window it runs in — live from `startsAt` up to, not including,
 * `endsAt` (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 */
final class SaleWindow
{
    private function __construct(
        public readonly int $points,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
    ) {}

    /**
     * The sale an item's three columns describe, or null when they describe
     * none: any of them missing, or a window that never opens.
     */
    public static function of(?int $points, ?CarbonInterface $startsAt, ?CarbonInterface $endsAt): ?self
    {
        if ($points === null || $startsAt === null || $endsAt === null || ! $endsAt->greaterThan($startsAt)) {
            return null;
        }

        return new self($points, CarbonImmutable::instance($startsAt)->utc(), CarbonImmutable::instance($endsAt)->utc());
    }

    public function isLiveAt(CarbonInterface $at): bool
    {
        return $at->greaterThanOrEqualTo($this->startsAt) && $at->lessThan($this->endsAt);
    }

    /**
     * The part of [$from, $to) that falls inside the sale, or null when none
     * of it does.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function clip(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $start = CarbonImmutable::instance($from)->utc()->max($this->startsAt);
        $end = CarbonImmutable::instance($to)->utc()->min($this->endsAt);

        return $end->greaterThan($start) ? [$start, $end] : null;
    }
}
