<?php

namespace App\Domain\Finance\Exceptions;

use RuntimeException;

/**
 * A card payment could not be priced.
 *
 * Distinct from the InvalidArgumentExceptions PaymentAmountCalculator
 * still throws for genuine programmer errors (a plan with no price, a
 * non-positive top-up amount): this one is an **operational
 * misconfiguration** that a correct caller can hit at runtime, so the HTTP
 * layer turns it into a 503 rather than letting it surface as a 500.
 *
 * The realistic cause is charging in a currency that is not the base
 * currency with no active exchange rate for it — e.g. PAYMERA_CURRENCY=SYP
 * against a USD base, before anyone has entered a USD→SYP rate. Every
 * payment then fails identically until an admin fixes the rate, which is a
 * deployment fault and not something the member did.
 *
 * Deliberately NOT a PaymeraException: nothing was asked of the gateway.
 * The failure happens before a single byte goes out, so it must not be
 * logged or reported as a gateway error.
 */
class PaymentPricingException extends RuntimeException
{
    public static function missingExchangeRate(string $from, string $to): self
    {
        return new self(
            "No active exchange rate from {$from} to {$to}; cannot price a card payment."
        );
    }
}
