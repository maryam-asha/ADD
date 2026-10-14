<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Settings\Services\SettingService;
use InvalidArgumentException;

/**
 * The single place points convert to and from money
 * (docs/superpowers/specs/2026-09-12-add-point-system-design.md,
 * Invariant 4). Nothing else in the app may read `point.rate_usd` —
 * tests/Guards/PointConversionIsCentralizedTest.php enforces that.
 *
 * All arithmetic is bcmath on decimal strings: the rate is a decimal
 * and PRD decision #15 forbids float for monetary values. Credits floor
 * and debits ceil, so rounding never favours the member (Invariant 2).
 */
class PointConversionService
{
    /**
     * The setting key this service alone reads. Exposed as a constant so
     * callers that only need to route on the key (e.g.
     * UpdateSettingRequest, choosing validation rules) — never on the
     * rate value — can reference it without spelling out the literal
     * string themselves; tests/Guards/PointConversionIsCentralizedTest.php
     * scans for that literal, so a second occurrence outside this file
     * would trip the guard even though it never reads the rate.
     */
    public const SETTING_KEY = 'point.rate_usd';

    public function __construct(
        private readonly SettingService $settings,
        private readonly CurrencyConversionService $currency,
    ) {}

    public function rate(): string
    {
        return (string) $this->settings->get(self::SETTING_KEY, '0.10');
    }

    /**
     * The rate as clients receive it: always a plain 4-decimal string
     * ("0.1000"), whatever form an admin typed it in. The dashboard's
     * exact arithmetic parses only plain decimals.
     */
    public function publishedRate(): string
    {
        return bcadd($this->rate(), '0', 4);
    }

    /**
     * The published rate without trailing zeros ("0.1", "0.0125") — the
     * form every amount reaches the member app in.
     */
    public function plainRate(): string
    {
        return rtrim(rtrim($this->publishedRate(), '0'), '.');
    }

    /**
     * Exact, unrounded USD value of a point count. Accepts negatives —
     * the ledger stores debits as negative integers and a refund needs
     * to render them.
     */
    public function pointsToUsd(int $points): string
    {
        return bcmul((string) $points, $this->rate(), 2);
    }

    /**
     * The USD a point count is worth at the rate's full precision. The rate
     * may carry up to four decimal places (UpdateSettingRequest), so a
     * point can be worth a fraction of a cent that pointsToUsd() — cents,
     * for display — would cut off. A price that has to buy `$points` back
     * (ReceivedMoneyConverter::pointsToAmount) is computed from this.
     */
    public function pointsToUsdExact(int $points): string
    {
        return bcmul((string) $points, $this->rate(), 10);
    }

    /**
     * Credit direction — floors. bcdiv at scale 0 truncates toward zero,
     * which is floor for the non-negative input this accepts.
     */
    public function usdToPoints(string $usd): int
    {
        $this->rejectNegative($usd);

        return (int) bcdiv($usd, $this->rate(), 0);
    }

    /**
     * Debit direction — ceils. Deliberately not ceil((float) $usd / ...):
     * a float division at an exact boundary (e.g. 1.80 / 0.10) can land
     * just above the integer and wrongly charge an extra whole point.
     */
    public function usdToPointsCeil(string $usd): int
    {
        $this->rejectNegative($usd);

        $quotient = bcdiv($usd, $this->rate(), 4);
        $floor = bcdiv($quotient, '1', 0);

        return (int) (bccomp($quotient, $floor, 4) > 0 ? bcadd($floor, '1', 0) : $floor);
    }

    /**
     * Null when the target currency has no exchange rate, matching
     * CurrencyConversionService's existing contract so callers keep
     * their null handling.
     */
    public function pointsToCurrency(int $points, string $currencyCode): ?string
    {
        $usd = $this->pointsToUsd($points);
        $baseCurrency = $this->currency->baseCurrencyCode();

        if ($currencyCode === $baseCurrency) {
            return $usd;
        }

        $converted = $this->currency->convert((float) $usd, $baseCurrency, $currencyCode);

        if ($converted === null) {
            return null;
        }

        // At the currency's own decimals — a whole pound for SYP — rounded up,
        // the direction every amount asked for is rounded in.
        $decimals = (int) (Currency::query()->whereKey($currencyCode)->value('decimal_places') ?? 2);
        $factor = 10 ** $decimals;

        return number_format(ceil(round($converted * $factor, 6)) / $factor, $decimals, '.', '');
    }

    private function rejectNegative(string $usd): void
    {
        if (bccomp($usd, '0', 4) < 0) {
            throw new InvalidArgumentException("Cannot convert a negative amount to points: {$usd}");
        }
    }
}
