<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Invoicing\Exceptions\InvoicingException;

/**
 * Money received in one currency, counted in the invoice's currency at
 * today's rates. bcmath end to end; truncated to the invoice currency's
 * decimals — money received is credited conservatively, as everywhere
 * else in the app (points floor).
 */
class InvoiceMoney
{
    private const SCALE = 16;

    public function __construct(private readonly CurrencyConversionService $currencies) {}

    /** @return array{amount: string, rate: string} */
    public function toInvoiceCurrency(string $amount, string $from, string $to): array
    {
        if ($from === $to) {
            return ['amount' => bcadd($amount, '0', $this->decimals($to)), 'rate' => '1'];
        }

        $rate = bcdiv($this->rateToBase($from), $this->rateToBase($to), self::SCALE);

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

    private function rateToBase(string $code): string
    {
        if ($code === $this->currencies->baseCurrencyCode()) {
            return '1';
        }

        return $this->currencies->rateToBase($code)
            ?? throw new InvoicingException('api.invoice.no_exchange_rate', 422, ['currency' => $code]);
    }

    private function decimals(string $code): int
    {
        return (int) (Currency::query()->whereKey($code)->value('decimal_places') ?? 2);
    }
}
