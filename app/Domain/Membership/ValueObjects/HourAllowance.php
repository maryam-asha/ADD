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

    /** The plan's own coworking hours, which come with a seat. */
    public function isCoworkingSeat(): bool
    {
        return $this->row === null;
    }
}
