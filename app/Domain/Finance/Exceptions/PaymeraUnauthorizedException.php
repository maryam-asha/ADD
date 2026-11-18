<?php

namespace App\Domain\Finance\Exceptions;

/**
 * Paymera ErrorCode 1. Two causes, indistinguishable from the response:
 * the API key is wrong, or — in production only — this server's public IP
 * is not the one registered with the bank. Both are deployment faults, not
 * anything the cardholder did, so this must never be shown to a member as
 * a payment failure.
 */
class PaymeraUnauthorizedException extends PaymeraException
{
    public static function make(): self
    {
        return new self(
            'Paymera rejected the credentials (ErrorCode 1) — check PAYMERA_API_KEY, '
            .'and in production that this server\'s public IP is registered with the bank.',
            1
        );
    }
}
