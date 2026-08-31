<?php

namespace Tests\Guards;

use App\Domain\Finance\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/decisions/multi-currency-support.md (+ 2026-08-31 addendum): exactly
 * one `currencies` row carries `is_base = true` at all times, and that row
 * must be active. Which code it is is no longer a guaranteed invariant —
 * PATCH currencies/{currency}/base can reassign it — so these tests assert
 * the structural invariant only, not the identity of the base currency.
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
        $base = Currency::query()->where('is_base', true)->first();

        $this->assertNotNull($base, 'No base currency found.');
        $this->assertTrue((bool) $base->is_active, 'The base currency must be active.');
    }

    public function test_invariant_holds_after_reassignment(): void
    {
        $newBase = Currency::factory()->create(['code' => 'EUR', 'is_active' => true, 'is_base' => false]);

        Currency::where('is_base', true)->update(['is_base' => false]);
        $newBase->update(['is_base' => true]);

        $this->assertSame(1, Currency::query()->where('is_base', true)->count());
        $this->assertSame(1, Currency::query()->where('is_base', true)->where('is_active', true)->count());
    }
}
