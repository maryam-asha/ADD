<?php

namespace App\Services\Otp;

interface OtpProvider
{
    /**
     * Dispatch an OTP code to a phone number via WhatsApp — the sole verified
     * channel (final decision, see docs/decisions/otp-channel.md for the
     * history of what was reconsidered and reversed).
     * Returns whether the gateway accepted the send request — not delivery confirmation.
     */
    public function send(string $phone, string $code, string $provider): bool;
}
