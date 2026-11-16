<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Support\PlainAmount;

/**
 * What a number of points comes to in each active currency, base currency
 * first, as the desk reads a figure it is about to collect in cash. A
 * currency without an exchange rate yet is left out rather than refused.
 *
 * Scoped to the request (PaymentServiceProvider): a list that prices every
 * row reads the active currencies once and prices each distinct figure once.
 * Each conversion still asks the converter for its rate, so a page of orders
 * with different totals costs a few queries per distinct total.
 */
class PointsInCurrencies
{
    /** @var list<string>|null */
    private ?array $codes = null;

    /** @var array<int, list<array{currency: string, value: string}>> */
    private array $priced = [];

    public function __construct(private readonly ReceivedMoneyConverter $converter) {}

    /**
     * @return list<array{currency: string, value: string}>
     */
    public function for(int $points): array
    {
        return $this->priced[$points] ??= $this->price($points);
    }

    /**
     * @return list<array{currency: string, value: string}>
     */
    private function price(int $points): array
    {
        $amounts = [];

        foreach ($this->codes() as $code) {
            try {
                $amounts[] = ['currency' => $code, 'value' => PlainAmount::of($this->converter->pointsToAmount($points, $code))];
            } catch (PaymentPricingException) {
                // No rate for this currency yet: it is simply not offered.
            }
        }

        return $amounts;
    }

    /** @return list<string> */
    private function codes(): array
    {
        return $this->codes ??= Currency::query()
            ->where('is_active', true)
            ->orderByDesc('is_base')
            ->orderBy('order')
            ->pluck('code')
            ->values()
            ->all();
    }
}
