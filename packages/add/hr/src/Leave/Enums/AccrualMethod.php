<?php

namespace Add\Hr\Leave\Enums;

/**
 * How a policy credits the balance: a share every completed month, the whole
 * year at once when its cycle starts (1 January or the hire anniversary,
 * GrantCycle; a new hire per HireGrant), or not at all (only manual
 * adjustments).
 */
enum AccrualMethod: string
{
    case Monthly = 'monthly';
    case AnnualGrant = 'annual_grant';
    case None = 'none';
}
