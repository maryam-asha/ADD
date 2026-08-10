<?php

namespace App\Domain\Membership\Enums;

/**
 * Manual-polymorphic owner discriminator shared by `Wallet` and
 * `Membership` (docs/decisions/wallet-subscription-ownership.md) — an
 * individual member or a company can each hold a wallet/membership.
 */
enum OwnerType: string
{
    case User = 'user';
    case Company = 'company';
}
