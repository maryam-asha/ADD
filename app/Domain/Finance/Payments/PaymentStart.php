<?php

namespace App\Domain\Finance\Payments;

/**
 * What the caller does after a payment method has opened a payment.
 *
 *  - `done`             — nothing: it was settled on the spot (wallet points).
 *  - `redirect`         — send the member to `redirectUrl` (a gateway's hosted page).
 *  - `pay_at_reception` — tell the member to pay at the desk; staff settle it.
 *
 * Built only through the named constructors so a `redirect` can never exist
 * without a URL, nor a `done` with one.
 */
final readonly class PaymentStart
{
    public const DONE = 'done';

    public const REDIRECT = 'redirect';

    public const PAY_AT_RECEPTION = 'pay_at_reception';

    private function __construct(
        public string $kind,
        public ?string $redirectUrl = null,
    ) {}

    public static function done(): self
    {
        return new self(self::DONE);
    }

    public static function redirect(string $url): self
    {
        return new self(self::REDIRECT, $url);
    }

    public static function payAtReception(): self
    {
        return new self(self::PAY_AT_RECEPTION);
    }
}
