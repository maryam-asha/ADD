<?php

namespace Add\Hr\Time\Enums;

/**
 * What a day was planned to be for an employee.
 */
enum DayKind: string
{
    case Shift = 'shift';
    case Flexible = 'flexible';
    case Unscheduled = 'unscheduled';
    case Rest = 'rest';
    case Holiday = 'holiday';
    case Leave = 'leave';
    case NotEmployed = 'not_employed';
}
