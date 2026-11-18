<?php

namespace Add\Hr\Leave\Enums;

/**
 * When a yearly grant renews: on 1 January for everyone, or on each
 * employee's hire anniversary. Only an annual-grant policy has a cycle;
 * monthly accrual follows the calendar month.
 */
enum GrantCycle: string
{
    case CalendarYear = 'calendar_year';
    case HireAnniversary = 'hire_anniversary';
}
