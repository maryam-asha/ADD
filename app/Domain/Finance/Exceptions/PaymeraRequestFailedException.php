<?php

namespace App\Domain\Finance\Exceptions;

/**
 * Anything that is not success and not ErrorCode 1: Paymera's generic
 * failure (ErrorCode 100), an unparseable body, a non-2xx HTTP status, or
 * a connection that never landed.
 */
class PaymeraRequestFailedException extends PaymeraException
{
    public static function vendorError(int $errorCode, string $message): self
    {
        return new self("Paymera error {$errorCode}: {$message}", $errorCode);
    }

    public static function malformedResponse(string $detail): self
    {
        return new self("Paymera returned an unusable response: {$detail}");
    }

    /**
     * The request never reached Paymera (timeout, DNS, TLS, refused). It is
     * critical that create-payment treats this as fatal and does NOT retry:
     * a timed-out create may well have created a payment server-side, and a
     * retry would charge the member twice.
     */
    public static function networkError(string $detail): self
    {
        return new self("Could not reach Paymera: {$detail}");
    }
}
