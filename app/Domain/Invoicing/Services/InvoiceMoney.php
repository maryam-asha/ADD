<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Invoicing\Exceptions\InvoicingException;
use Illuminate\Support\Carbon;

/**
 * Money received in one currency, counted in the invoice's currency at
 * the rates effective on the day it was paid. bcmath end to end;
 * truncated to the invoice currency's decimals — money received is credited conservatively, as everywhere
 * else in the app (points floor).
 */
class InvoiceMoney
{
    private const SCALE = 16;

    public function __construct(private readonly CurrencyConversionService $currencies) {}

    /** @return array{amount: string, rate: string} */
    public function toInvoiceCurrency(string $amount, string $from, string $to, string $onDate): array
    {
        if ($from === $to) {
            return ['amount' => bcadd($amount, '0', $this->decimals($to)), 'rate' => '1'];
        }

        $rate = bcdiv($this->rateToBase($from, $onDate), $this->rateToBase($to, $onDate), self::SCALE);

        return [
            'amount' => bcadd(bcmul($amount, $rate, self::SCALE), '0', $this->decimals($to)),
            'rate' => bcadd($rate, '0', 10),
        ];
    }

    /** The smallest amount of `$code`: 0.01 for two decimals, 1 for none. */
    public function minorUnit(string $code): string
    {
        $decimals = $this->decimals($code);

        return $decimals === 0 ? '1' : '0.'.str_repeat('0', $decimals - 1).'1';
    }

    /** The rate in force at the end of `$onDate` (not the cached current one). */
    private function rateToBase(string $code, string $onDate): string
    {
        if ($code === $this->currencies->baseCurrencyCode()) {
            return '1';
        }

        $rate = ExchangeRate::query()
            ->where('currency_code', $code)
            ->where('effective_from', '<=', Carbon::parse($onDate)->endOfDay())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('rate_to_base');

        return $rate === null
            ? throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $code])
            : (string) $rate;
    }

    /** True when `$amount` has a non-zero digit past what `$code` can hold. */
    public function exceedsDecimals(string $amount, string $code): bool
    {
        $fraction = rtrim(explode('.', $amount)[1] ?? '', '0');

        return strlen($fraction) > $this->decimals($code);
    }

    public function decimals(string $code): int
    {
        return (int) (Currency::query()->whereKey($code)->value('decimal_places') ?? 2);
    }
}
