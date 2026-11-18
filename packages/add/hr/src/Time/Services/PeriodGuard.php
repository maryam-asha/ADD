<?php

namespace Add\Hr\Time\Services;

use Add\Hr\Support\HrException;
use Add\Hr\Time\Enums\PeriodStatus;
use Add\Hr\Time\Models\TimePeriod;
use Carbon\CarbonInterface;

/**
 * Nothing that changes attendance — a punch, a roster change, an entry,
 * an overtime decision, an exception resolution — lands in a locked month.
 */
class PeriodGuard
{
    public function isLocked(CarbonInterface $date): bool
    {
        return TimePeriod::query()
            ->where('year', $date->year)
            ->where('month', $date->month)
            ->where('status', PeriodStatus::Locked->value)
            ->exists();
    }

    public function assertOpen(CarbonInterface $date): void
    {
        if ($this->isLocked($date)) {
            HrException::fail('hr::time.period_locked', ['month' => $date->format('Y-m')]);
        }
    }
}
