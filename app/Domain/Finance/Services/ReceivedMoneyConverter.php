<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\DataTransferObjects\ConvertedMoney;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\Currency;
use InvalidArgumentException;

/**
 * Money received — cash at the desk, a transfer, any amount that is not
 * already points — turned into points, and back into the money a number of
 * points costs. The one place that happens, so the reception top-up and
 * every settlement credit exactly the same for the same money.
 *
 * bcmath end to end (Invariant 5): the exchange rate comes from
 * CurrencyConversionService as its stored decimal string, and the point
 * conversion is PointConversionService's, which owns the rounding rules.
 * The two directions round against each other on purpose — points floor,
 * the quoted amount ceils — so paying `pointsToAmount($n)` always buys at
 * least `$n` points (Invariant 2).
 */
class ReceivedMoneyConverter
{
    /**
     * Enough places to hold `amount x rate` exactly: rates are stored to 10
     * decimal places, and no currency has more than 6.
     */
    private const SCALE = 16;

    public function __construct(
        private readonly CurrencyConversionService $currency,
        private readonly PointConversionService $points,
    ) {}

    /**
     * @throws PaymentPricingException when `$currencyCode` has no exchange rate
     */
    public function toPoints(string $amount, string $currencyCode): ConvertedMoney
    {
        $amount = $this->normalize($amount);
        $base = $this->currency->baseCurrencyCode();
        $rate = null;
        $exactBase = $amount;

        if ($currencyCode !== $base) {
            $rate = $this->currency->rateToBase($currencyCode)
                ?? throw PaymentPricingException::missingExchangeRate($currencyCode, $base);

            $exactBase = bcmul($amount, $rate, self::SCALE);
        }

        return new ConvertedMoney(
            // From the exact value, never from the cents below: rounding
            // first could push a sub-point remainder over a whole point.
            points: $this->points->usdToPoints($exactBase),
            baseAmount: bcadd($exactBase, '0', 2),
            rateToBase: $rate,
            pointRateUsd: $this->points->rate(),
        );
    }

    /**
     * The money needed to buy `$points`, rounded up to the currency's
     * decimal places.
     *
     * @throws PaymentPricingException when `$currencyCode` has no exchange rate
     */
    public function pointsToAmount(int $points, string $currencyCode): string
    {
        $base = $this->currency->baseCurrencyCode();
        $usd = $this->points->pointsToUsdExact($points);
        $decimals = $this->decimalPlaces($currencyCode);

        if ($currencyCode === $base) {
            return $this->ceilTo($usd, '1', $decimals);
        }

        $rate = $this->currency->rateToBase($currencyCode)
            ?? throw PaymentPricingException::missingExchangeRate($base, $currencyCode);

        return $this->ceilTo($usd, $rate, $decimals);
    }

    /**
     * The smallest amount with `$decimals` places that, multiplied by
     * `$rate`, reaches `$target`. bcdiv truncates, so the quotient is at or
     * below the exact answer; one step up when it falls short. Checked by
     * multiplying back rather than by inspecting the division's tail, which
     * a finite scale could cut off.
     */
    private function ceilTo(string $target, string $rate, int $decimals): string
    {
        $amount = bcdiv($target, $rate, $decimals);

        if (bccomp(bcmul($amount, $rate, self::SCALE), $target, self::SCALE) < 0) {
            $amount = bcadd($amount, bcpow('10', (string) -$decimals, $decimals), $decimals);
        }

        return $amount;
    }

    /**
     * The amount as a plain decimal string bcmath accepts. Request rules say
     * `numeric`, which also lets through `1e3`, ` 5 `, `5.`, `+5` and `.5` —
     * the same money, spelled in ways bcmath refuses with a ValueError. A
     * plain spelling passes untouched; an exponent is expanded through a
     * float, which only an exotic input takes and which is exact for any
     * amount a person hands over.
     *
     * @throws InvalidArgumentException when `$amount` is not a number at all
     */
    private function normalize(string $amount): string
    {
        $amount = trim($amount);

        if (preg_match('/^\+?(\d*)(?:\.(\d*))?$/', $amount, $parts) === 1 && ($parts[1] !== '' || ($parts[2] ?? '') !== '')) {
            $whole = $parts[1] === '' ? '0' : $parts[1];
            $fraction = $parts[2] ?? '';

            return $fraction === '' ? $whole : "{$whole}.{$fraction}";
        }

        if (is_numeric($amount)) {
            return rtrim(rtrim(sprintf('%.10F', (float) $amount), '0'), '.');
        }

        throw new InvalidArgumentException("Not an amount of money: [{$amount}].");
    }

    private function decimalPlaces(string $currencyCode): int
    {
        return (int) (Currency::query()->whereKey($currencyCode)->value('decimal_places') ?? 2);
    }
}
