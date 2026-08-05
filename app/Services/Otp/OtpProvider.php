<?php

namespace App\Services\Otp;

interface OtpProvider
{
    /**
     * Dispatch an OTP code to a phone number via the given carrier ("mtn"|"syriatel").
     * Returns whether the gateway accepted the send request — not delivery confirmation.
     */
    public function send(string $phone, string $code, string $provider): bool;
}
