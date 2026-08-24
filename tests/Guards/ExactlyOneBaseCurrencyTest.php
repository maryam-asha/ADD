<?php

namespace Tests\Guards;

use App\Domain\Finance\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/decisions/multi-currency-support.md: the base currency is fixed via
 * `currencies.is_base` — not user-configurable, and never multi-base.
 * CurrencyConversionService/CurrencyResolver both assume exactly one
 * always-active base row exists; this guard checks that invariant against
 * the migrated schema state itself, not just prose.
 */
class ExactlyOneBaseCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_one_currency_is_marked_as_base(): void
    {
        $this->assertSame(1, Currency::query()->where('is_base', true)->count());
    }

    public function test_the_base_currency_is_active(): void
    {
        $this->assertSame(
            'USD',
            Currency::query()->where('is_base', true)->where('is_active', true)->value('code')
        );
    }
}
