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
    | "mock" logs the code instead of sending it — the only driver until a
    | real WhatsApp Business integration (Meta Cloud API or a BSP reseller)
    | is wired up. Swapping the driver here is the only change needed once
    | real credentials exist.
    |
    */

    'otp' => [
        'driver' => env('OTP_DRIVER', 'mock'),
    ],

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'template_name' => env('WHATSAPP_OTP_TEMPLATE', 'otp_verification'),
    ],

    'sptoday' => [
        'base_url' => env('SPTODAY_BASE_URL', 'https://api-v2.sp-today.com/api/v1'),
        'api_key' => env('SP_TODAY_KEY'),
    ],

    'ttlock' => [
        // Verified against https://euopen.ttlock.com/doc/api/ — no EU-specific
        // host was found documented anywhere on that portal; every example
        // shows this generic host. UNVERIFIED against a real account —
        // confirm before relying on it in production (see
        // docs/decisions/qr-lock-unlock.md's verification findings).
        'base_url' => env('TTLOCK_BASE_URL', 'https://api.sciener.com'),
        'client_id' => env('TTLOCK_CLIENT_ID'),
        'client_secret' => env('TTLOCK_CLIENT_SECRET'),
        'username' => env('TTLOCK_USERNAME'),
        // Raw password — TTLockClient MD5-hashes it at call time, per the
        // vendor's documented requirement. Never store a pre-hashed value here.
        'password' => env('TTLOCK_PASSWORD'),
    ],

];
