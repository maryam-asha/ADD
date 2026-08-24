<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Services\CurrencyResolver;
use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The `currency` header now validates against active rows in the
 * admin-managed `currencies` table (docs/decisions/multi-currency-support.md)
 * — SYP and USD are seeded by the `currencies` migration itself, so
 * RefreshDatabase is enough to exercise both without a factory.
 */
class CurrencyResolverTest extends TestCase
{
    use RefreshDatabase;

    private function requestWithCurrencyHeader(?string $value): Request
    {
        $server = $value === null ? [] : ['HTTP_CURRENCY' => $value];

        return Request::create('/', 'GET', server: $server);
    }

    public function test_a_valid_header_wins_over_the_stored_preference(): void
    {
        $user = User::factory()->make(['preferred_currency' => 'SYP']);

        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader('USD'), $user);

        $this->assertSame('USD', $resolved);
    }

    public function test_the_header_is_case_insensitive(): void
    {
        $user = User::factory()->make(['preferred_currency' => 'SYP']);

        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader('usd'), $user);

        $this->assertSame('USD', $resolved);
    }

    public function test_an_invalid_header_falls_back_to_the_stored_preference(): void
    {
        $user = User::factory()->make(['preferred_currency' => 'USD']);

        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader('EUR'), $user);

        $this->assertSame('USD', $resolved);
    }

    public function test_a_missing_header_falls_back_to_the_stored_preference(): void
    {
        $user = User::factory()->make(['preferred_currency' => 'USD']);

        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader(null), $user);

        $this->assertSame('USD', $resolved);
    }

    public function test_no_user_and_no_header_falls_back_to_usd(): void
    {
        $resolved = (new CurrencyResolver)->resolve($this->requestWithCurrencyHeader(null), null);

        $this->assertSame('USD', $resolved);
    }
}
