<?php

namespace App\Support\Presenters;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\ValueObjects\SaleWindow;
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
        $pointsLabel = __('api.space.price_points', ['points' => number_format($points)], $locale);

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
     * A sale live right now, as the member sees it: the original price
     * (struck through by the app), the sale price, and when the sale ends.
     * Null when nothing is on sale — before it starts, after it ends, or a
     * "sale" no lower than the price. `$hourly` labels a space's rate;
     * otherwise a total, such as a package's. `$endsKey` names the
     * "ends …" sentence (`api.space.sale_ends`, `api.package.sale_ends`).
     *
     * @return array{points: int, original_label: string, sale_label: string, ends_at: string, ends_label: string}|null
     */
    public function sale(?SaleWindow $sale, int $originalPoints, ?User $user, bool $hourly, string $endsKey, ?string $locale = null): ?array
    {
        if ($sale === null || ! $sale->isLiveAt(now()) || $sale->points >= $originalPoints) {
            return null;
        }

        $locale ??= app()->getLocale();
        $label = fn (int $points) => $hourly
            ? $this->hourly($points, $user, $locale)['price_label']
            : $this->total($points, $user, $locale)['price'];
        $endsAt = app(LocalTime::class)->toLocal($sale->endsAt);

        return [
            'points' => $sale->points,
            'original_label' => $label($originalPoints),
            'sale_label' => $label($sale->points),
            'ends_at' => $endsAt->toIso8601String(),
            'ends_label' => __($endsKey, ['date' => $endsAt->format('Y-m-d H:i')], $locale),
        ];
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

        return $amount === null ? [$this->points->pointsToUsd($points), 'USD'] : [$amount, $currency];
    }
}
