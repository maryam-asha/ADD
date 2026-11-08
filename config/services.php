<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP / WhatsApp Gateway
    |--------------------------------------------------------------------------
    |
    | "mock" logs the code instead of sending it; "hala_sender" sends it over
    | WhatsApp through Hala Sender. Swapping the driver here is the only
    | change needed — calling code never changes.
    |
    */

    'otp' => [
        'driver' => env('OTP_DRIVER', 'mock'),
    ],

    // WhatsApp via Hala Sender — the live driver (OTP_DRIVER=hala_sender).
    // device_id is required: the linked WhatsApp device (from the Hala Sender
    // dashboard) every OTP is sent from.
    'hala_sender' => [
        'base_url' => env('HALA_SENDER_BASE_URL', 'https://clientarea.hala-sender.com'),
        'api_key' => env('HALA_SENDER_API_KEY'),
        'device_id' => env('HALA_SENDER_DEVICE_ID'),
    ],

    'sptoday' => [
        'base_url' => env('SPTODAY_BASE_URL', 'https://api-v2.sp-today.com/api/v1'),
        'api_key' => env('SP_TODAY_KEY'),
    ],

    'ttlock' => [
        // Checked against the real account on 2026-11-08: the EU API host is
        // euapi.ttlock.com (euopen.ttlock.com is only the docs portal).
        'base_url' => env('TTLOCK_BASE_URL', 'https://euapi.ttlock.com'),
        'client_id' => env('TTLOCK_CLIENT_ID'),
        'client_secret' => env('TTLOCK_CLIENT_SECRET'),
        'username' => env('TTLOCK_USERNAME'),
        // Raw password — TTLockClient MD5-hashes it at call time, per the
        // vendor's documented requirement. Never store a pre-hashed value here.
        'password' => env('TTLOCK_PASSWORD'),
    ],

];
