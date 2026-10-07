<?php

namespace App\Domain\Pricing\ValueObjects;

/**
 * One line of a price breakdown: `base` (the list price, positive), then
 * what was taken off it as negative points — `sale` (the automatic offer;
 * the key the app already reads), `plan_discount`, `coupon`. `percentage`,
 * `code` and `name` (the offer's) are for the line's label.
 */
final class PriceLine
{
    public function __construct(
        public readonly string $key,
        public readonly int $points,
        public readonly ?int $percentage = null,
        public readonly ?string $code = null,
        public readonly ?string $name = null,
    ) {}
}
