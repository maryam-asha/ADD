<?php

namespace App\Services\Otp\Exceptions;

use RuntimeException;

class OtpThrottledException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Too many OTP requests. Retry after {$retryAfterSeconds}s.");
    }
}
