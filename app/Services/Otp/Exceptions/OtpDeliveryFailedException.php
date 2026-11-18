<?php

namespace App\Services\Otp\Exceptions;

use RuntimeException;

/**
 * The gateway refused or never answered the send. The code was never
 * delivered, so the caller must not tell the member it was.
 */
class OtpDeliveryFailedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The OTP gateway did not accept the send.');
    }
}
