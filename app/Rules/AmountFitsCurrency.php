<?php

namespace App\Rules;

use App\Domain\Finance\Models\Currency;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount of money has no more decimals than its currency uses
 * (`currencies.decimal_places`): a Syrian pound amount is whole — there are
 * no piasters in use — while a dollar amount may carry cents. The currency
 * is the request's own, else the base currency.
 */
class AmountFitsCurrency implements ValidationRule
{
    public function __construct(private readonly ?string $currencyCode) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;
        }

        $code = $this->currencyCode ?? Currency::baseCode();
        $decimals = (int) (Currency::query()->whereKey($code)->value('decimal_places') ?? 2);

        // Trailing zeros are not precision: "5000.00" is a whole 5000.
        $fraction = rtrim(explode('.', (string) $value)[1] ?? '', '0');

        if (strlen($fraction) > $decimals) {
            $fail($decimals === 0
                ? __('api.validation.amount_must_be_whole', ['currency' => $code])
                : __('api.validation.amount_too_precise', ['currency' => $code, 'decimals' => $decimals]));
        }
    }
}
