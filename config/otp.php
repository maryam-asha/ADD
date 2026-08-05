<?php

return [

    'code_length' => 6,

    'expires_after_minutes' => 5,

    // Minimum wait before a phone number may request a new code.
    'resend_cooldown_seconds' => 60,

    // Max verify attempts allowed against a single issued code before it is
    // burned, independent of its time-based expiry.
    'max_verify_attempts' => 5,

];
