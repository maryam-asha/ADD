<?php

namespace App\Domain\Finance\Payments\Exceptions;

use RuntimeException;

/**
 * A payment method that cannot be used for what was asked: it is not in
 * config/payments.php, it is switched off, or it is not a gateway when a
 * gateway was needed.
 *
 * A RuntimeException rather than a logic error because it can be reached
 * from data: a payment row may name a method that has since been removed
 * from config.
 */
class UnknownPaymentMethodException extends RuntimeException
{
    public static function unknown(string $key): self
    {
        return new self("Payment method [{$key}] is not registered.");
    }

    public static function disabled(string $key): self
    {
        return new self("Payment method [{$key}] is disabled.");
    }

    public static function notAGateway(string $key): self
    {
        return new self("Payment method [{$key}] is not a gateway.");
    }
}
