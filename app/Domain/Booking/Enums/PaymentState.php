<?php

namespace App\Domain\Booking\Enums;

enum PaymentState: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
}
