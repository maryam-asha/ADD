<?php

namespace App\Domain\Finance\Services;

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
     * Exact, unrounded USD value of a point count. Accepts negatives —
     * the ledger stores debits as negative integers and a refund needs
     * to render them.
     */
    public function pointsToUsd(int $points): string
    {
        return bcmul((string) $points, $this->rate(), 2);
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

        return $converted !== null ? number_format($converted, 2, '.', '') : null;
    }

    private function rejectNegative(string $usd): void
    {
        if (bccomp($usd, '0', 4) < 0) {
            throw new InvalidArgumentException("Cannot convert a negative amount to points: {$usd}");
        }
    }
}
