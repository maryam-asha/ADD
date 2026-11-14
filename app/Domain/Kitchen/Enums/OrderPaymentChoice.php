<?php

namespace App\Domain\Kitchen\Enums;

/**
 * How the member chose to pay for an order. `Bill` is the old way (the
 * order joins the open session or booking bill); the rest pay at send.
 * `Card` is stored as the choice; the payment row's method is the gateway's
 * key (PaymeraGateway::KEY).
 */
enum OrderPaymentChoice: string
{
    case Bill = 'bill';
    case Wallet = 'wallet';
    case Card = 'card';
    case Cash = 'cash';
}
