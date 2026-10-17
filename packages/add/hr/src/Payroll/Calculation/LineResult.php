<?php

namespace Add\Hr\Payroll\Calculation;

use Add\Hr\Payroll\Models\PayComponent;

/**
 * One computed line, `amount` already in the run's currency and rounded.
 */
final class LineResult
{
    public function __construct(
        public readonly PayComponent $component,
        public readonly string $amount,
        public readonly ?string $basis = null,
        public readonly ?string $quantity = null,
        public readonly ?string $rate = null,
        public readonly ?string $originalAmount = null,
        public readonly ?string $originalCurrency = null,
    ) {}
}
