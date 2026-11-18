<?php

namespace Add\Hr\Leave\Enums;

/**
 * When a higher seniority tier starts paying: at the next grant, or on the
 * service anniversary that reaches it, as the difference for what is left of
 * the cycle. Only matters for a calendar-year cycle; an anniversary cycle
 * renews on that day anyway.
 */
enum TierTiming: string
{
    case NextCycle = 'next_cycle';
    case OnAnniversary = 'on_anniversary';
}
