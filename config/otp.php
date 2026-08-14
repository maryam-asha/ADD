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

];
