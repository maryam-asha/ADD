<?php

namespace App\Domain\Membership\ValueObjects;

use App\Domain\Membership\Models\PlanSpaceHour;

/**
 * Hours a plan gives on a space: its own coworking hours ($row null,
 * counted only inside its daily window) or a free-hours benefit ($row, any
 * time). $hours null is unlimited.
 */
final class HourAllowance
{
    public function __construct(
        public readonly ?PlanSpaceHour $row,
        public readonly ?float $hours,
        public readonly bool $windowed,
    ) {}

    public function isUnlimited(): bool
    {
        return $this->hours === null;
    }

    /** `coworking` for the plan's own hours, `space` for a free-hours benefit — PlanHourUsage::allowance_kind. */
    public function kind(): string
    {
        return $this->row === null ? 'coworking' : 'space';
    }

    /** The plan's own coworking hours, which come with a seat. */
    public function isCoworkingSeat(): bool
    {
        return $this->row === null;
    }
}
