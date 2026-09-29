<?php

namespace App\Domain\Membership\Enums;

/** Why a plan will not cover a coworking session, in the order they are checked. */
enum CoverageReason: string
{
    case NoPlan = 'no_plan';
    case PlanExpired = 'plan_expired';
    case PlanWithoutHours = 'plan_without_hours';
    case HoursExhausted = 'hours_exhausted';
    case OutsideWindow = 'outside_window';
}
