<?php

namespace Add\Hr\Leave\Enums;

/**
 * How a policy credits the balance: a share every completed month, the whole
 * year at once on 1 January (prorated in the hire year), or not at all (only
 * manual adjustments).
 */
enum AccrualMethod: string
{
    case Monthly = 'monthly';
    case AnnualGrant = 'annual_grant';
    case None = 'none';
}
