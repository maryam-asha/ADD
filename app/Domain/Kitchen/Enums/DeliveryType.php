<?php

namespace App\Domain\Kitchen\Enums;

/** Where the kitchen takes an order: a desk, a space, the counter, or a written note. */
enum DeliveryType: string
{
    case Desk = 'desk';
    case Space = 'space';
    case Pickup = 'pickup';
    case Note = 'note';
}
