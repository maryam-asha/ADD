<?php

return [

    'code_length' => 6,

    // Local/dev convenience only: when set, every OTP request returns this
    // code instead of a random one, so no one has to read it out of the log.
    // Leave unset in any environment reachable by real users.
    'fixed_code' => env('OTP_FIXED_CODE'),

    'expires_after_minutes' => 5,

    // Minimum wait before a phone number may request a new code.
    'resend_cooldown_seconds' => 60,

    // Max verify attempts allowed against a single issued code before it is
    // burned, independent of its time-based expiry.
    'max_verify_attempts' => 5,

    // How long the one-time token issued after a successful verify() stays
    // spendable. Short-lived on purpose: it only has to bridge the gap
    // between the "enter code" screen and the very next "set new password"
    // request.
    'reset_token_ttl_minutes' => env('OTP_RESET_TOKEN_TTL_MINUTES', 10),

];
