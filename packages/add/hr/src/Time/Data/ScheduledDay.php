<?php

namespace Add\Hr\Time\Data;

use Add\Hr\Time\Enums\DayKind;
use Add\Hr\Time\Models\ShiftTemplate;
use Carbon\CarbonImmutable;

/**
 * What a local work date was planned to be for one employee. `start` and
 * `end` are UTC instants, set for a shift only. `planned` says whether a
 * roster entry or a work pattern decided it — without either, the global
 * calendar did, and attendance is not expected.
 */
final class ScheduledDay
{
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly DayKind $kind,
        public readonly bool $planned,
        public readonly ?ShiftTemplate $shift = null,
        public readonly ?CarbonImmutable $start = null,
        public readonly ?CarbonImmutable $end = null,
    ) {}

    /**
     * Expected at work (a shift, a flexible day, or an unplanned working day).
     */
    public function isWorking(): bool
    {
        return in_array($this->kind, [DayKind::Shift, DayKind::Flexible, DayKind::Unscheduled], true);
    }

    public function crossesMidnight(): bool
    {
        return $this->shift?->crossesMidnight() ?? false;
    }
}
