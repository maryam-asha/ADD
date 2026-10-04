<?php

namespace App\Domain\Pricing\Enums;

/**
 * Which automatic reduction a price was composed with — the better of the
 * item's sale and the member's plan discount, never both, or neither.
 * Recorded so an extension and a check-out keep the same choice.
 */
enum PriceChoice: string
{
    case List = 'list';
    case Sale = 'sale';
    case Plan = 'plan';
}
