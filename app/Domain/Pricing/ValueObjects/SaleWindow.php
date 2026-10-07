<?php

namespace App\Domain\Pricing\ValueObjects;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The window an offer runs in — live from `startsAt` up to, not including,
 * `endsAt`; either end may be open (null), meaning always
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 */
final class SaleWindow
{
    private function __construct(
        public readonly ?CarbonImmutable $startsAt,
        public readonly ?CarbonImmutable $endsAt,
    ) {}

    /** An offer's window: from `$startsAt`, until `$endsAt`, either open. */
    public static function between(?CarbonInterface $startsAt, ?CarbonInterface $endsAt): self
    {
        return new self(
            $startsAt !== null ? CarbonImmutable::instance($startsAt)->utc() : null,
            $endsAt !== null ? CarbonImmutable::instance($endsAt)->utc() : null,
        );
    }

    public function isLiveAt(CarbonInterface $at): bool
    {
        return ($this->startsAt === null || $at->greaterThanOrEqualTo($this->startsAt))
            && ($this->endsAt === null || $at->lessThan($this->endsAt));
    }

    /**
     * The part of [$from, $to) that falls inside the window, or null when
     * none of it does.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function clip(CarbonInterface $from, CarbonInterface $to): ?array
    {
        $start = CarbonImmutable::instance($from)->utc();
        $end = CarbonImmutable::instance($to)->utc();

        if ($this->startsAt !== null) {
            $start = $start->max($this->startsAt);
        }

        if ($this->endsAt !== null) {
            $end = $end->min($this->endsAt);
        }

        return $end->greaterThan($start) ? [$start, $end] : null;
    }

    public function overlaps(CarbonInterface $from, CarbonInterface $to): bool
    {
        return $this->clip($from, $to) !== null;
    }
}
