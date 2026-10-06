<?php

use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Finance\Payments\Methods\WalletMethod;

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

        'wallet' => [
            'driver' => WalletMethod::class,
            'enabled' => env('PAYMENTS_WALLET_ENABLED', true),
        ],

        'cash' => [
            'driver' => CashMethod::class,
            'enabled' => env('PAYMENTS_CASH_ENABLED', true),
        ],

        'paymera' => [
            'driver' => PaymeraGateway::class,
            'enabled' => env('PAYMENTS_PAYMERA_ENABLED', true),
        ],

    ],

    /*
    | How long a plan bought with cash is held for the member to pay at the
    | desk before payments:expire-pending lets it go.
    */
    'cash_plan_hold_hours' => (int) env('PAYMENTS_CASH_PLAN_HOLD_HOURS', 48),

    /*
    |--------------------------------------------------------------------------
    | Smallest wallet top-up
    |--------------------------------------------------------------------------
    |
    | The fewest points a member may add to their wallet from the app. Only
    | the top-up has a floor; everything else is paid at whatever it costs.
    | The app is told what it is worth in the charged currency.
    */
    'min_top_up_points' => (int) env('PAYMENTS_MIN_TOP_UP_POINTS', 10),

    /*
    |--------------------------------------------------------------------------
    | Prepayment window
    |--------------------------------------------------------------------------
    |
    | A booking or registration made with a prepaid method (a gateway) is
    | held this many minutes — never past its start — for the card to go
    | through or the member to choose another method; then it is cancelled
    | (payments:cancel-expired-prepayments).
    |
    */
    'prepayment_window_minutes' => (int) env('PAYMENTS_PREPAYMENT_WINDOW_MINUTES', 15),

];
