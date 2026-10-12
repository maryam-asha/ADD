<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\ExchangeRate;
use InvalidArgumentException;

/**
 * Converts between the three units a card payment has to reconcile:
 * ADD Points (the ledger), USD (the base currency), and the whole amount
 * the gateway is charged in its currency.
 *
 * All money arithmetic is bcmath on decimal strings — PRD decision #15
 * forbids float for monetary values, and this is exactly the place a
 * float would look harmless and then lose a piaster per transaction.
 *
 * Point conversion is delegated to PointConversionService rather than
 * reimplemented: it is the only class allowed to read the point rate
 * setting (tests/Guards/PointConversionIsCentralizedTest.php, which scans
 * for that setting key as a literal — so do not spell it out here), and it
 * owns the rounding direction rule — credits floor, so a member is never
 * granted more value than they paid for.
 */
class PaymentAmountCalculator
{
    public function __construct(
        private readonly PointConversionService $points,
        private readonly CurrencyConversionService $currency,
    ) {}

    /**
     * What a number of points due (a plan, a booking, a session) costs by
     * card. The direction matters: the points ARE the price, and the charge
     * is derived from them, rounded up. Doing it the other way (charge
     * first, then convert to points) could grant fewer points than the
     * price after flooring, leaving a member who paid in full unable to
     * afford what they just bought. A plan's points are
     * PriceQuoteService's, its sale included.
     *
     * @return array{0: int, 1: int} [points, chargedAmount]
     */
    public function forPoints(int $points): array
    {
        if ($points <= 0) {
            throw new InvalidArgumentException('There is nothing to charge for zero points.');
        }

        return [$points, $this->pointsToCharged($points)];
    }

    /**
     * What a member-chosen top-up amount buys.
     *
     * Floors, matching the reception till: the member is never credited
     * more value than the card actually covers. An amount too small to buy
     * a single point is refused here rather than taken — the request layer
     * checks the same thing so the member sees a validation error, and this
     * is the backstop for any other caller.
     *
     * @return array{0: int, 1: int} [points, chargedAmount]
     */
    public function forTopUp(?int $chargedAmount): array
    {
        if ($chargedAmount === null || $chargedAmount <= 0) {
            throw new InvalidArgumentException('A top-up requires a positive amount.');
        }

        $points = $this->points->usdToPoints($this->chargedAmountToUsd($chargedAmount));

        if ($points < 1) {
            throw new InvalidArgumentException('Amount is worth less than one point.');
        }

        return [$points, $chargedAmount];
    }

    /**
     * The smallest top-up a member may make from the app, in points and in
     * the charged currency — rounded to the nearest whole unit (13,840, not
     * 13,840.01), since that is what the member is asked to type. Paying it
     * is accepted as the minimum.
     *
     * @return array{points: int, amount: string, currency: string}
     */
    public function minimumTopUp(): array
    {
        $points = max(1, (int) config('payments.min_top_up_points'));

        // Half up to a whole unit, from the exact amount rather than
        // pointsToCharged()'s rounded-up one: the minimum is the nearest whole
        // unit, which may be just below it.
        $whole = bcdiv(bcadd($this->exactCharged($points), '0.5', 4), '1', 0);

        return [
            'points' => $points,
            'amount' => $whole,
            'currency' => $this->currencyCode(),
        ];
    }

    /**
     * What the gateway is charged for a point count: a whole number of the
     * charged currency, as Paymera takes it, rounded UP.
     *
     * Rounding up is deliberate and is the house-favouring direction here:
     * the member receives exactly the points quoted, and any fraction of a
     * unit is paid rather than absorbed.
     */
    public function pointsToCharged(int $points): int
    {
        return (int) $this->ceilString($this->exactCharged($points));
    }

    /**
     * A point count's exact value in the charged currency.
     */
    private function exactCharged(int $points): string
    {
        return $this->usdToCharged($this->points->pointsToUsd($points));
    }

    /**
     * The USD value of an amount in the charged currency.
     */
    public function chargedAmountToUsd(int $chargedAmount): string
    {
        return $this->chargedToUsd((string) $chargedAmount);
    }

    /**
     * The base-currency-to-charged-currency rate this payment was priced
     * at, or null when the charge is already in the base currency and no
     * conversion happened.
     *
     * Recorded on the ledger row so a historical credit can be re-derived
     * later, when the live rate has long since moved — the same reason
     * WalletTopUpController stores `exchange_rate_to_base` for cash taken
     * in a foreign currency.
     */
    public function appliedExchangeRate(): ?string
    {
        $base = $this->currency->baseCurrencyCode();
        $target = $this->currencyCode();

        if ($target === $base) {
            return null;
        }

        $rate = ExchangeRate::current($target);

        return $rate !== null ? (string) $rate->rate_to_base : null;
    }

    /**
     * The currency the card is actually charged in.
     */
    public function currencyCode(): string
    {
        return (string) config('paymera.currency');
    }

    private function usdToCharged(string $usd): string
    {
        $base = $this->currency->baseCurrencyCode();
        $target = $this->currencyCode();

        if ($target === $base) {
            return $usd;
        }

        $converted = $this->currency->convert((float) $usd, $base, $target);

        if ($converted === null) {
            throw PaymentPricingException::missingExchangeRate($base, $target);
        }

        return number_format($converted, 4, '.', '');
    }

    private function chargedToUsd(string $charged): string
    {
        $base = $this->currency->baseCurrencyCode();
        $source = $this->currencyCode();

        if ($source === $base) {
            return $charged;
        }

        $converted = $this->currency->convert((float) $charged, $source, $base);

        if ($converted === null) {
            throw PaymentPricingException::missingExchangeRate($source, $base);
        }

        return number_format($converted, 4, '.', '');
    }

    /**
     * ceil() on a decimal string. Not ceil((float) $value): at an exact
     * boundary a float can land a hair above the integer and charge one
     * extra minor unit — the same trap PointConversionService documents.
     */
    private function ceilString(string $value): string
    {
        $floor = bcdiv($value, '1', 0);

        return bccomp($value, $floor, 4) > 0 ? bcadd($floor, '1', 0) : $floor;
    }
}
