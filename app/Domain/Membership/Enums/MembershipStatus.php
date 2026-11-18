<?php

namespace App\Domain\Membership\Enums;

/**
 * Only `Active` is ever assigned in Phase 3 — no cancel/expire flow exists
 * yet (docs/decisions/phase-3-membership-plan-wallet-mechanics.md). The
 * column exists so later phases have somewhere to put `Cancelled` /
 * `Expired` without a migration; don't add cases here that have no writer.
 */
enum MembershipStatus: string
{
    case Active = 'active';
}
