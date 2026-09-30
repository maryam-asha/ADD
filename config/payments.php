<?php

use App\Domain\Finance\Payments\Methods\PaymeraGateway;

/*
|--------------------------------------------------------------------------
| Payment methods
|--------------------------------------------------------------------------
|
| Every way a member (or the reception desk) can pay is a driver listed
| here, keyed by the value stored in `payments.method`. The order below is
| the order clients are shown the methods in.
|
| A driver is any class implementing PaymentMethodDriver; one that also
| implements PaymentGateway is asked for the outcome of a payment it opened
| (status sync, reconcile, reversal) and names the ledger method its
| credits record. To add a method, add a class and a line here: the payment
| lifecycle (sync, reconcile, reversal, crediting an accepted payment)
| reaches methods only through the registry. What still names Paymera
| itself is its own surface — the card start flow, its callback/trigger
| routes and its `paymera` log channel.
|
| `enabled` only stops NEW payments from being opened with a method. A
| disabled gateway is still consulted to settle payments already in flight,
| because the member's money is at the gateway whatever the flag says.
|
*/

return [

    'methods' => [

        'paymera' => [
            'driver' => PaymeraGateway::class,
            'enabled' => env('PAYMENTS_PAYMERA_ENABLED', true),
        ],

    ],

];
