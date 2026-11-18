<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A currency's cash step has no more decimals than the currency itself
 * uses. The dashboard quotes top-ups in multiples of the step, and every
 * amount must fit the currency's decimals (AmountFitsCurrency) — a 0.50
 * step on a pound with no decimals would quote amounts the top-up then
 * refuses. Takes the decimals as given, not from the database, since the
 * request may be setting them in the same call.
 */
class CashStepFitsDecimals implements ValidationRule
{
    public function __construct(private readonly mixed $decimalPlaces) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value) || ! is_numeric($this->decimalPlaces)) {
            return;
        }

        $decimals = (int) $this->decimalPlaces;

        // Trailing zeros are not precision: "5.00" is a whole 5.
        $fraction = rtrim(explode('.', (string) $value)[1] ?? '', '0');

        if (strlen($fraction) > $decimals) {
            $fail($decimals === 0
                ? __('api.validation.cash_step_must_be_whole')
                : __('api.validation.cash_step_too_precise', ['decimals' => $decimals]));
        }
    }
}
