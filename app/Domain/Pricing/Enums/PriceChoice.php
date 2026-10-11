<?php

namespace App\Domain\Pricing\Enums;

/**
 * Which automatic reduction a price was composed with — the better of the
 * best automatic offer and the member's plan discount, never both, or
 * neither. Recorded (in the discount ledger) so an extension and a
 * check-out keep the same choice.
 */
enum PriceChoice: string
{
    case List = 'list';
    case Offer = 'offer';
    case Plan = 'plan';
}
