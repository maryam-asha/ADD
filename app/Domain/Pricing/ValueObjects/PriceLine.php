<?php

namespace App\Domain\Pricing\ValueObjects;

/**
 * One line of a price breakdown: `base` (the list price, positive), then
 * what was taken off it as negative points — `sale`, `plan_discount`,
 * `coupon`. `percentage` and `code` are for the line's label.
 */
final class PriceLine
{
    public function __construct(
        public readonly string $key,
        public readonly int $points,
        public readonly ?int $percentage = null,
        public readonly ?string $code = null,
    ) {}
}
