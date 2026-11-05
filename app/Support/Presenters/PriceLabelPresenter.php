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
use Carbon\CarbonImmutable;
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
     * A space's hourly rate, per hour.
     *
     * @return array{points: int, price: int|float, currency: string, unit: string, unit_label: string}
     */
    public function hourly(int $points, ?User $user, ?string $locale = null): array
    {
        return $this->rate($points, 'hour', $user, $locale ?? app()->getLocale());
    }

    /**
     * A package / membership plan's rate, per the plan's period — null when
     * it is not a day, a week or a month.
     *
     * @return array{points: int, price: int|float, currency: string, unit: ?string, unit_label: ?string}
     */
    public function plan(int $points, ?int $durationDays, ?User $user, ?string $locale = null): array
    {
        return $this->rate($points, $this->unitForDays($durationDays), $user, $locale ?? app()->getLocale());
    }

    /**
     * A rate as numbers, with no label: the app puts `currency` (the symbol
     * shown beside an amount) and, if it wants, `unit_label` beside `price`.
     *
     * @return array{points: int, price: int|float, currency: string, unit: ?string, unit_label: ?string}
     */
    private function rate(int $points, ?string $unit, ?User $user, string $locale): array
    {
        [$amount, $currency] = $this->amountFor($points, $user);

        return [
            'points' => $points,
            'price' => $this->numeric($amount),
            'currency' => $this->currencySymbol($currency, $locale),
            ...$this->unit($unit, $locale),
        ];
    }

    /** A number of points in the display currency, as a number. */
    private function priceOf(int $points, ?User $user): int|float
    {
        return $this->numeric($this->amountFor($points, $user)[0]);
    }

    /** A plain amount ("3", "1.5") as a number: whole amounts as int. */
    private function numeric(string $amount): int|float
    {
        return (float) $amount == (int) (float) $amount ? (int) (float) $amount : (float) $amount;
    }

    /**
     * What a price is per, as a key (`hour`, `day`, `week`, `month`) and in
     * the member's language — both null for a price per nothing.
     *
     * @return array{unit: ?string, unit_label: ?string}
     */
    private function unit(?string $unit, string $locale): array
    {
        return ['unit' => $unit, 'unit_label' => $unit === null ? null : __("api.price_unit.{$unit}", [], $locale)];
    }

    /** A period counted in days as a unit — null when it is not a day, a week or a month. */
    private function unitForDays(?int $days): ?string
    {
        return match ($days) {
            1 => 'day',
            7 => 'week',
            30 => 'month',
            default => null,
        };
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
     * A number of points as money in the display currency — the amount (a
     * number), its label, and the currency's symbol as the label shows it.
     *
     * @return array{amount: int|float, label: string, currency: string}
     */
    public function money(int $points, ?User $user, ?string $locale = null): array
    {
        [$amount, $currency] = $this->amountFor($points, $user);

        return ['amount' => $this->numeric($amount), 'label' => $this->currencyLabel($amount, $currency, $locale), 'currency' => $this->currencySymbol($currency, $locale)];
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
     * Amounts are numbers, in points and in the display currency, whose
     * symbol is `currency`. `$unit` is what the price is per, as in the rate
     * beside it — `hour` for a space, the plan's period for a package, null
     * for an event. `$group` holds the sentences (`api.space`,
     * `api.package`: `sale_ends`, `sale_discount_*`).
     *
     * @return array{points: int, price: int|float, original_points: int, original_price: int|float, discount_points: int, discount_amount: int|float, discount_percent: int, discount_label: string, currency: string, unit: ?string, unit_label: ?string, ends_at: ?string, ends_label: ?string}|null
     */
    public function sale(?OfferPrice $offer, int $originalPoints, ?User $user, ?string $unit, string $group, ?string $locale = null): ?array
    {
        return $offer === null ? null : $this->saleAt($offer->points, $offer->endsAt, $originalPoints, $user, $unit, $group, $locale);
    }

    /**
     * The same `sale` block for a price that is not an automatic offer's —
     * a space's hourly rate under the member's plan discount
     * (SpaceBrowseService). `$endsAt` null: nothing to say about ending.
     *
     * @return array<string, mixed>|null
     */
    public function saleAt(int $points, ?CarbonImmutable $endsAt, int $originalPoints, ?User $user, ?string $unit, string $group, ?string $locale = null): ?array
    {
        if ($points >= $originalPoints) {
            return null;
        }

        $locale ??= app()->getLocale();
        $endsAt = $endsAt !== null ? app(LocalTime::class)->toLocal($endsAt) : null;

        $discount = $originalPoints - $points;
        // Rounded down: the discount announced is never more than the real one.
        $percent = intdiv($discount * 100, $originalPoints);

        return [
            'points' => $points,
            'price' => $this->priceOf($points, $user),
            'original_points' => $originalPoints,
            'original_price' => $this->priceOf($originalPoints, $user),
            'discount_points' => $discount,
            'discount_amount' => $this->priceOf($discount, $user),
            'discount_percent' => $percent,
            // Under one percent, "0% off" would say nothing: the amount instead.
            'discount_label' => $percent >= 1
                ? __("{$group}.sale_discount_percent", ['percent' => $percent], $locale)
                : __("{$group}.sale_discount_amount", ['amount' => $this->total($discount, $user, $locale)['price']], $locale),
            'currency' => $this->currencySymbol($this->amountFor($originalPoints, $user)[1], $locale),
            ...$this->unit($unit, $locale),
            'ends_at' => $endsAt?->toIso8601String(),
            'ends_label' => $endsAt !== null ? __("{$group}.sale_ends", ['date' => $endsAt->format('Y-m-d H:i')], $locale) : null,
        ];
    }

    /**
     * A plan's built-in saving, as the member sees it — the `saving` block
     * beside `sale`: what the reference costs (`reference_price`), what the
     * plan saves, and its price per unit (`per_unit_points`, rounded UP to a
     * whole point like an offer rate, E8 — it never understates the price).
     * Amounts are numbers; `currency` is the symbol beside them. The unit is
     * apart (`unit`, `unit_label`): an hour against a space, the reference
     * plan's period otherwise — named by the reference plan, with `unit`
     * null, when that period is not a day, a week or a month.
     * Null when the plan has no saving.
     *
     * @return array{compared_to: string, reference_points: int, reference_price: int|float, saving_points: int, saving_percent: int, saving_label: string, per_unit_points: int, per_unit_price: int|float, currency: string, unit: ?string, unit_label: ?string, saving_description: string}|null
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
        $unit = $this->savingUnit($saving);

        return [
            'compared_to' => $saving->comparedTo,
            'reference_points' => $saving->referencePoints,
            'reference_price' => $this->priceOf($saving->referencePoints, $user),
            'saving_points' => $saving->savingPoints,
            'saving_percent' => $saving->savingPercent,
            // Under one percent, "0%" would say nothing: the amount instead.
            'saving_label' => $saving->savingPercent >= 1
                ? __('api.package.saving_percent', ['percent' => $saving->savingPercent], $locale)
                : __('api.package.saving_amount', ['amount' => $savingAmount], $locale),
            'per_unit_points' => $perUnit,
            'per_unit_price' => $this->priceOf($perUnit, $user),
            'currency' => $this->currencySymbol($this->amountFor($saving->referencePoints, $user)[1], $locale),
            'unit' => $unit,
            'unit_label' => $unit === null ? $saving->referenceName : $this->unit($unit, $locale)['unit_label'],
            'saving_description' => $this->savingDescription($saving, $perUnit, $savingAmount, $user, $locale),
        ];
    }

    /** What a saving is counted in: an hour against a space, the reference plan's period otherwise. */
    private function savingUnit(PlanSaving $saving): ?string
    {
        return $saving->comparedTo === 'space' ? 'hour' : $this->unitForDays($saving->referenceDays);
    }

    /**
     * The saving as one sentence in Markdown, where `saving_label` is the
     * plain badge: "Get **12 months** for **900 $** (regular price 1,500 $)
     * — save **40%** (6,000 pts). That's 750 pts/mo." What you get, its
     * price and the saving are in bold. The quantity is counted in the
     * reference's unit — an hour for a space, the reference plan's period otherwise; a period
     * that is not a day, a week or a month is named by the reference plan
     * instead. What one unit comes to is in points (user ruling, 2026-10-08):
     * a money rate shows fractions ("1.20 $") a whole number of points never
     * does. A day pass compared with hours of a space is "a full day", its
     * hours the comparison only — a per-hour rate would say nothing there.
     */
    private function savingDescription(PlanSaving $saving, int $perUnit, string $savingAmount, ?User $user, string $locale): string
    {
        $unit = $this->savingUnit($saving);

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
        $currency = $this->currencyRow($currencyCode);

        $floatVal = (float) $amount;
        $isWhole = $floatVal == (int) $floatVal;
        $decimals = $isWhole ? 0 : ($currency?->decimal_places ?? 2);

        return number_format($floatVal, $decimals).' '.$this->currencySymbol($currencyCode, $locale);
    }

    /**
     * The symbol a currency stands beside an amount with, as in
     * currencyLabel(): `$`, `ل.س` — in English the ISO code (`SYP`) for an
     * Arabic symbol.
     */
    public function currencySymbol(string $currencyCode, ?string $locale = null): string
    {
        return $this->resolveSymbol($currencyCode, $this->currencyRow($currencyCode)?->symbol, $locale ?? app()->getLocale());
    }

    private function currencyRow(string $currencyCode): ?Currency
    {
        // Memoised for the request: a listing formats a price per card.
        return $this->currencyRows[$currencyCode] ??= Currency::find($currencyCode);
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
