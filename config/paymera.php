<?php

/*
|--------------------------------------------------------------------------
| Paymera eGate (API v4.0)
|--------------------------------------------------------------------------
|
| Switching between the test and production gateway is a .env change only —
| nothing in app/ names a Paymera host. That is not merely a preference:
| tests/Guards/NetworkIsolationTest.php fails the build if any external
| host appears under app/, database/ or routes/, and deliberately excludes
| config/. The two hosts below are the single place they may be written.
|
| In production Paymera binds the credentials to the merchant server's
| public IP, so a working test key will still return ErrorCode 1 until that
| IP is registered with the bank.
|
*/

return [

    'base_url' => env('PAYMERA_BASE_URL', 'https://egate-t.paymera.cc'),

    // Never logged, never serialized into a response — see PaymeraClient's
    // redaction and tests/Feature/Finance/PaymeraSecretsAreNeverLoggedTest.php.
    'api_key' => env('PAYMERA_API_KEY'),

    // Exactly 8 characters, and a string: Paymera's own examples show it
    // both quoted and unquoted, so every comparison normalizes to string.
    'terminal_id' => (string) env('PAYMERA_TERMINAL_ID', ''),

    'default_lang' => env('PAYMERA_DEFAULT_LANG', 'en'),

    'timeout' => (int) env('PAYMERA_TIMEOUT', 15),

    /*
     * How long a payment may sit at status P before the reconciler stops
     * expecting it to resolve on its own. One final status check is made
     * before it is written off.
     */
    'pending_expiry_minutes' => (int) env('PAYMERA_PENDING_EXPIRY_MINUTES', 60),

    'saved_cards_enabled' => (bool) env('PAYMERA_SAVED_CARDS_ENABLED', false),

    /*
     * The currency actually charged. Paymera takes `amount` as a whole
     * number of it, sent as it is.
     */
    'currency' => env('PAYMERA_CURRENCY', 'SYP'),

];
