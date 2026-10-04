<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Membership\Models\Plan;
use InvalidArgumentException;

/**
 * Converts between the three units a card payment has to reconcile:
 * ADD Points (the ledger), USD (the base currency), and the gateway's
 * integer minor units in the charged currency.
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
     * What a plan costs, in points and in gateway minor units.
     *
     * The direction matters: the plan's price IS the point figure, and the
     * charge is derived from it. Doing it the other way (charge first, then
     * convert to points) could grant fewer points than the plan costs after
     * flooring, leaving a member who paid the full price unable to afford
     * the plan they just bought.
     *
     * @return array{0: int, 1: int} [points, amountMinor]
     */
    public function forPlan(Plan $plan): array
    {
        $points = (int) $plan->price_points;

        if ($points <= 0) {
            throw new InvalidArgumentException("Plan {$plan->id} has no positive price to charge.");
        }

        return [$points, $this->pointsToMinor($points)];
    }

    /**
     * What a number of points due (a booking, a session) costs by card —
     * the same direction and rounding as a plan: the points are the price,
     * and the charge is derived from them, rounded up.
     *
     * @return array{0: int, 1: int} [points, amountMinor]
     */
    public function forPoints(int $points): array
    {
        if ($points <= 0) {
            throw new InvalidArgumentException('There is nothing to charge for zero points.');
        }

        return [$points, $this->pointsToMinor($points)];
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
     * @return array{0: int, 1: int} [points, amountMinor]
     */
    public function forTopUp(?int $amountMinor): array
    {
        if ($amountMinor === null || $amountMinor <= 0) {
            throw new InvalidArgumentException('A top-up requires a positive amount.');
        }

        $points = $this->points->usdToPoints($this->minorToUsd($amountMinor));

        if ($points < 1) {
            throw new InvalidArgumentException('Amount is worth less than one point.');
        }

        return [$points, $amountMinor];
    }

    /**
     * The smallest top-up a member may make from the app, in points and in
     * the charged currency — rounded to the nearest whole unit (13,840, not
     * 13,840.01), since that is what the member is asked to type. Paying it
     * is accepted as the minimum.
     *
     * @return array{points: int, amount_minor: int, amount: string, currency: string}
     */
    public function minimumTopUp(): array
    {
        $points = max(1, (int) config('payments.min_top_up_points'));
        $factor = bcpow('10', (string) $this->exponent(), 0);

        // Half up to a whole unit: add half a unit, then truncate.
        // From the exact amount, not pointsToMinor()'s rounded-up one: the
        // minimum is the nearest whole unit, which may be just below it.
        $whole = bcdiv(bcadd((string) $this->exactMinor($points), bcdiv($factor, '2', 0), 0), $factor, 0);

        return [
            'points' => $points,
            'amount_minor' => (int) bcmul($whole, $factor, 0),
            'amount' => $whole,
            'currency' => $this->currencyCode(),
        ];
    }

    /**
     * Gateway minor units for a point count, rounding UP to the minor unit.
     *
     * Rounding up is deliberate and is the house-favouring direction here:
     * the member receives exactly the points quoted, and any sub-minor-unit
     * remainder is paid rather than absorbed. Rounding down would hand out
     * a fraction of a unit of value per purchase.
     */
    public function pointsToMinor(int $points): int
    {
        $minor = $this->exactMinor($points);

        // The gateway counts in minor units its currency may not use: a card
        // charge in Syrian pounds is a whole pound (currencies.decimal_places
        // = 0), so it is rounded up to a whole number of the units it uses.
        $decimals = (int) (Currency::query()->whereKey($this->currencyCode())->value('decimal_places') ?? $this->exponent());
        $step = 10 ** max(0, $this->exponent() - $decimals);

        return (int) (ceil($minor / $step) * $step);
    }

    /**
     * Gateway minor units for a point count, rounded up to the minor unit
     * only — before pointsToMinor() takes it to a unit the currency uses.
     */
    private function exactMinor(int $points): int
    {
        $usd = $this->points->pointsToUsd($points);
        $charged = $this->usdToCharged($usd);

        $factor = bcpow('10', (string) $this->exponent(), 0);
        $exact = bcmul($charged, $factor, 4);

        return (int) $this->ceilString($exact);
    }

    /**
     * The USD value of a gateway minor-unit amount.
     */
    public function minorToUsd(int $amountMinor): string
    {
        $factor = bcpow('10', (string) $this->exponent(), 0);
        $charged = bcdiv((string) $amountMinor, $factor, 4);

        return $this->chargedToUsd($charged);
    }

    /**
     * A gateway minor-unit amount as a decimal string in the currency it
     * was actually charged in — 250000 minor SYP units becomes "2500.00".
     *
     * This, not minorToUsd(), is what belongs in a ledger row's
     * `payment_amount` when `payment_currency` names the charged currency.
     * Writing the USD value next to a "SYP" label makes the pair a lie.
     */
    public function minorToCharged(int $amountMinor): string
    {
        $factor = bcpow('10', (string) $this->exponent(), 0);

        return bcdiv((string) $amountMinor, $factor, 2);
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

    private function exponent(): int
    {
        return (int) config('paymera.currency_exponent');
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
