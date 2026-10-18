<?php

namespace Add\Hr\Benefits\Enums;

use Carbon\CarbonInterface;

enum BenefitFrequency: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';
    case OneTime = 'one_time';

    /**
     * Whether a grant falls in the month starting `$month`.
     */
    public function dueIn(CarbonInterface $month, ?int $grantMonth): bool
    {
        return match ($this) {
            self::Monthly, self::OneTime => true,
            self::Quarterly => $month->month % 3 === 1,
            self::Yearly => $month->month === ($grantMonth ?? 1),
        };
    }

    /**
     * The period a grant in that month covers, which an employee is granted
     * once.
     */
    public function period(CarbonInterface $month): string
    {
        return match ($this) {
            self::Monthly => $month->format('Y-m'),
            self::Quarterly => $month->year.'-Q'.$month->quarter,
            self::Yearly => (string) $month->year,
            self::OneTime => 'once',
        };
    }
}
