<?php

namespace App\Support\Presenters;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\ValueObjects\OfferPrice;
use App\Domain\Pricing\ValueObjects\PlanSaving;
use App\Support\PlainAmount;
use App\Support\Time\LocalTime;
use Illuminate\Http\Request;

/**
 * Display-ready price labels derived from a point price: the amount in the
 * caller's currency with that currency's symbol, and the points themselves.
 *
 * Points remain the only stored price (point system Invariant 1); the
 * currency amount is derived at read time, in the currency the request asks
 * for — `currency` header, else the member's preference, else the base
 * currency (CurrencyResolver).
 */
class PriceLabelPresenter
{
    /** @var array<string, Currency|null> */
    private array $currencyRows = [];

    public function __construct(
        private readonly PointConversionService $points,
        private readonly CurrencyResolver $currencies,
    ) {}

    /**
     * A space's hourly rate: price and points as numbers along with labels.
     *
     * @return array{
     *     points: int,
     *     points_label: string,
     *     price: int|float,
     *     price_label: string,
     *     currency: string
     * }
     */
    public function hourly(int $points, ?User $user, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        $perHour = __('api.space.per_hour', [], $locale);
        [$amount, $currency] = $this->amountFor($points, $user);
        $numericPrice = (float) $amount == (int) (float) $amount ? (int) (float) $amount : (float) $amount;

        return [
            'points' => $points,
            'points_label' => __('api.space.price_points', ['points' => number_format($points)], $locale).' / '.$perHour,
            'price' => $numericPrice,
            'price_label' => $this->currencyLabel($amount, $currency, $locale).' / '.$perHour,
            'currency' => $currency,
        ];
    }

    /**
     * A package / membership plan's rate: price and points as numbers along with labels.
     *
     * @return array{
     *     points: int,
     *     points_label: string,
     *     price: int|float,
     *     price_label: string,
     *     currency: string
     * }
     */
    public function plan(int $points, ?User $user, ?string $locale = null, ?string $period = null): array
    {
        $locale ??= app()->getLocale();
        $period ??= __('api.package.per_month', [], $locale);
        [$amount, $currency] = $this->amountFor($points, $user);
        $numericPrice = (float) $amount == (int) (float) $amount ? (int) (float) $amount : (float) $amount;

        return [
            'points' => $points,
            'points_label' => $this->pointsLabel($points, $locale).' / '.$period,
            'price' => $numericPrice,
            'price_label' => $this->currencyLabel($amount, $currency, $locale).' / '.$period,
            'currency' => $currency,
        ];
    }

    /**
     * A whole amount due: price and points as numbers along with labels.
     *
     * @return array{
     *     points: int,
     *     points_label: string,
     *     price: string,
     *     price_numeric: int|float,
     *     price_label: string,
     *     price_point: string,
     *     currency: string
     * }
     */
    public function total(int $points, ?User $user, ?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        [$amount, $currency] = $this->amountFor($points, $user);
        $numericPrice = (float) $amount == (int) (float) $amount ? (int) (float) $amount : (float) $amount;

        $priceLabel = $this->currencyLabel($amount, $currency, $locale);
        $pointsLabel = $this->pointsLabel($points, $locale);

        return [
            'points' => $points,
            'points_label' => $pointsLabel,
            'price' => $priceLabel,
            'price_numeric' => $numericPrice,
            'price_label' => $priceLabel,
            'price_point' => $pointsLabel,
            'currency' => $currency,
        ];
    }

    /**
     * A number of points as the member reads it, e.g. "1,200 pts".
     */
    public function pointsLabel(int $points, ?string $locale = null): string
    {
        return __('api.space.price_points', ['points' => number_format($points)], $locale ?? app()->getLocale());
    }

    /**
     * The best automatic offer live right now for this member, as they see
     * it — the `sale` block (OfferResolver::hourlyNow / planNow): the
     * original price (struck through by the app), the offer price, how much
     * it takes off, and when the offer ends — both null for an offer with no
     * end. Null when there is no offer, or one no lower than the price.
     * `$hourly` labels a space's rate (the discount is then per hour too);
     * otherwise a total, such as a package's. `$group` holds the sentences
     * (`api.space`, `api.package`: `sale_ends`, `sale_discount_*`).
     *
     * @return array{points: int, original_points: int, original_label: string, sale_label: string, discount_points: int, discount_amount_label: string, discount_percent: int, discount_label: string, ends_at: ?string, ends_label: ?string}|null
     */
    public function sale(?OfferPrice $offer, int $originalPoints, ?User $user, bool $hourly, string $group, ?string $locale = null): ?array
    {
        if ($offer === null || $offer->points >= $originalPoints) {
            return null;
        }

        $locale ??= app()->getLocale();
        $label = fn (int $points) => $hourly
            ? $this->hourly($points, $user, $locale)['price_label']
            : $this->total($points, $user, $locale)['price'];
        $endsAt = $offer->endsAt !== null ? app(LocalTime::class)->toLocal($offer->endsAt) : null;

        $discount = $originalPoints - $offer->points;
        // Rounded down: the discount announced is never more than the real one.
        $percent = intdiv($discount * 100, $originalPoints);
        $discountLabel = $label($discount);

        return [
            'points' => $offer->points,
            'original_points' => $originalPoints,
            'original_label' => $label($originalPoints),
            'sale_label' => $label($offer->points),
            'discount_points' => $discount,
            'discount_amount_label' => $discountLabel,
            'discount_percent' => $percent,
            // Under one percent, "0% off" would say nothing: the amount instead.
            'discount_label' => $percent >= 1
                ? __("{$group}.sale_discount_percent", ['percent' => $percent], $locale)
                : __("{$group}.sale_discount_amount", ['amount' => $discountLabel], $locale),
            'ends_at' => $endsAt?->toIso8601String(),
            'ends_label' => $endsAt !== null ? __("{$group}.sale_ends", ['date' => $endsAt->format('Y-m-d H:i')], $locale) : null,
        ];
    }

    /**
     * A plan's built-in saving, as the member sees it — the `saving` block
     * beside `sale`: what the reference costs (`reference_label`), what the
     * plan saves, and its price per unit (`per_unit_label`, rounded UP to a
     * whole point like an offer rate, E8 — it never understates the price).
     * Null when the plan has no saving.
     *
     * @return array{compared_to: string, reference_points: int, reference_label: string, saving_points: int, saving_percent: int, saving_label: string, per_unit_label: string, saving_description: string}|null
     */
    public function saving(?PlanSaving $saving, ?User $user, ?string $locale = null): ?array
    {
        if ($saving === null) {
            return null;
        }

        $locale ??= app()->getLocale();
        $whole = bcdiv($saving->perUnitPoints, '1', 0);
        $perUnit = (int) (bccomp($saving->perUnitPoints, $whole, 2) > 0 ? bcadd($whole, '1') : $whole);
        $savingAmount = $this->total($saving->savingPoints, $user, $locale)['price'];

        return [
            'compared_to' => $saving->comparedTo,
            'reference_points' => $saving->referencePoints,
            'reference_label' => $this->total($saving->referencePoints, $user, $locale)['price'],
            'saving_points' => $saving->savingPoints,
            'saving_percent' => $saving->savingPercent,
            // Under one percent, "0%" would say nothing: the amount instead.
            'saving_label' => $saving->savingPercent >= 1
                ? __('api.package.saving_percent', ['percent' => $saving->savingPercent], $locale)
                : __('api.package.saving_amount', ['amount' => $savingAmount], $locale),
            'per_unit_label' => $saving->comparedTo === 'space'
                ? $this->hourly($perUnit, $user, $locale)['price_label']
                : __('api.package.per_unit', ['amount' => $this->total($perUnit, $user, $locale)['price'], 'unit' => (string) $saving->referenceName], $locale),
            'saving_description' => $this->savingDescription($saving, $perUnit, $savingAmount, $user, $locale),
        ];
    }

    /**
     * The saving as one sentence, where `saving_label` is the badge: "Get 12
     * months for 900 $ (regular price 1,500 $) — save 40% (6,000 pts).
     * That's 750 pts/mo." The quantity is counted in the reference's unit —
     * an hour for a space, the reference plan's period otherwise; a period
     * that is not a day, a week or a month is named by the reference plan
     * instead. What one unit comes to is in points (user ruling, 2026-10-08):
     * a money rate shows fractions ("1.20 $") a whole number of points never
     * does. A day pass compared with hours of a space is "a full day", its
     * hours the comparison only — a per-hour rate would say nothing there.
     */
    private function savingDescription(PlanSaving $saving, int $perUnit, string $savingAmount, ?User $user, string $locale): string
    {
        $unit = $saving->comparedTo === 'space' ? 'hour' : match ($saving->referenceDays) {
            1 => 'day',
            7 => 'week',
            30 => 'month',
            default => null,
        };

        $replace = [
            'price' => $this->total($saving->referencePoints - $saving->savingPoints, $user, $locale)['price'],
            'regular' => $this->total($saving->referencePoints, $user, $locale)['price'],
            // Under one percent, "0%" would say nothing: the amount instead.
            'saving' => $saving->savingPercent >= 1 ? "{$saving->savingPercent}%" : $savingAmount,
            'points' => $this->pointsLabel($saving->savingPoints, $locale),
            'per_unit' => $this->pointsLabel($perUnit, $locale),
        ];

        if ($saving->comparedTo === 'space' && $saving->planDays === 1) {
            return __('api.package.saving_description_day', $replace + [
                'quantity' => trans_choice('api.package.saving_quantity.hour', $saving->units, ['count' => number_format($saving->units)], $locale),
            ], $locale);
        }

        if ($unit === null) {
            return __('api.package.saving_description_plain', $replace + ['unit' => (string) $saving->referenceName], $locale);
        }

        return __('api.package.saving_description', $replace + [
            'quantity' => trans_choice("api.package.saving_quantity.{$unit}", $saving->units, ['count' => number_format($saving->units)], $locale),
            'per' => __("api.package.saving_per.{$unit}", [], $locale),
        ], $locale);
    }

    /** The resolved display currency for this request and member. */
    public function currencyFor(?User $user): string
    {
        return $this->currencies->resolve(request() ?? new Request, $user);
    }

    /**
     * Thousands-separated, with the currency's own symbol placed after the
     * number — the convention both `ل.س` and `$` read correctly in here,
     * and the one the dashboard already uses.
     *
     * When locale is English, Arabic symbols (e.g. `ل.س`) fall back to the
     * ISO code (`SYP`) to avoid mixed-language labels.
     */
    public function currencyLabel(string $amount, string $currencyCode, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        // Memoised for the request: a listing formats a price per card.
        $currency = $this->currencyRows[$currencyCode] ??= Currency::find($currencyCode);

        $floatVal = (float) $amount;
        $isWhole = $floatVal == (int) $floatVal;
        $decimals = $isWhole ? 0 : ($currency?->decimal_places ?? 2);
        $symbol = $this->resolveSymbol($currencyCode, $currency?->symbol, $locale);

        return number_format($floatVal, $decimals).' '.$symbol;
    }

    private function resolveSymbol(string $currencyCode, ?string $symbol, string $locale): string
    {
        if ($locale === 'ar') {
            if ($currencyCode === 'SYP') {
                return 'ل.س';
            }

            return $symbol ?: $currencyCode;
        }

        // Non-Arabic locales: if symbol is SYP or contains Arabic characters, use currency code.
        if ($currencyCode === 'SYP' || ($symbol !== null && preg_match('/\p{Arabic}/u', $symbol))) {
            return $currencyCode;
        }

        return $symbol ?: $currencyCode;
    }

    /**
     * The points in the display currency — or in USD, labelled as USD, when
     * that currency has no rate yet.
     *
     * @return array{0: string, 1: string} amount, currency code
     */
    private function amountFor(int $points, ?User $user): array
    {
        $currency = $this->currencyFor($user);
        $amount = $this->points->pointsToCurrency($points, $currency);

        return $amount === null ? [PlainAmount::of($this->points->pointsToUsd($points)), 'USD'] : [PlainAmount::of($amount), $currency];
    }
}
