<?php

namespace App\Domain\Membership\Enums;

/**
 * docs/decisions/wallet-points-categorization.md — extensible: a new
 * category is a new case here, not a schema change.
 */
enum WalletTransactionCategory: string
{
    case General = 'general';
    case Cafe = 'cafe';
    case PrintingInternet = 'printing_internet';
    case SpaceSpecific = 'space_specific';
}
