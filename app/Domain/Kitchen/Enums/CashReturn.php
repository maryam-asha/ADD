<?php

namespace App\Domain\Kitchen\Enums;

/**
 * What became of cash taken for an order that did not go ahead: still owed
 * to the member, handed back, or credited to their points instead.
 */
enum CashReturn: string
{
    case Owed = 'owed';
    case ReturnedInCash = 'returned_in_cash';
    case CreditedAsPoints = 'credited_as_points';
}
