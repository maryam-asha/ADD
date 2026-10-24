<?php

namespace Tests\Unit\Domain\Finance\Services;

use App\Domain\Finance\Services\SpTodayRateClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpTodayRateClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fakeBody(array $overrides = []): array
    {
        return array_replace_recursive([
            'ok' => true,
            'data' => [
                'unit' => 'new',
                'currencies' => [
                    ['code' => 'USD', 'cities' => ['damascus' => ['buy' => 132.25, 'sell' => 132.75], 'alhasakah' => ['buy' => 132.5, 'sell' => 133]]],
                    ['code' => 'EUR', 'cities' => ['damascus' => ['buy' => 153, 'sell' => 154.8]]],
                ],
            ],
        ], $overrides);
    }

    public function test_it_extracts_usd_damascus_buy_and_sell(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(), 200, [
            'X-RateLimit-Limit' => '100',
            'X-RateLimit-Remaining' => '99',
        ])]);

        $result = app(SpTodayRateClient::class)->fetchUsdDamascusRates();

        $this->assertSame(132.75, $result['sell']);
        $this->assertSame(132.25, $result['buy']);
        $this->assertSame($this->fakeBody(), $result['raw']);
    }

    public function test_it_asks_for_the_new_lira(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(), 200)]);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/currencies?unit=new'));
    }

    public function test_it_throws_when_the_rates_are_in_the_old_lira(): void
    {
        // What sp-today answers when `unit` is ignored: the same rate, a
        // hundred times larger. Never divided down — refused.
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(['data' => [
            'unit' => 'old',
            'currencies' => [['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225, 'sell' => 13275]]]],
        ]]), 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('new lira');

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_the_unit_is_missing(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response([
            'ok' => true,
            'data' => ['currencies' => [['code' => 'USD', 'cities' => ['damascus' => ['buy' => 132.25, 'sell' => 132.75]]]]],
        ], 200)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('new lira');

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_on_a_non_2xx_response(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response('', 401)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_ok_is_not_true(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response(['ok' => false], 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_usd_is_missing_from_the_currency_list(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(['data' => ['currencies' => [['code' => 'EUR', 'cities' => ['damascus' => ['buy' => 1, 'sell' => 2]]]]]]), 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }

    public function test_it_throws_when_the_damascus_city_is_missing_for_usd(): void
    {
        // Built directly rather than via fakeBody()'s array_replace_recursive:
        // that helper merges numerically-indexed arrays element-wise, so a
        // partial override of 'cities' would silently keep the base's
        // 'damascus' key instead of actually removing it.
        Http::fake(['api-v2.sp-today.com/*' => Http::response([
            'ok' => true,
            'data' => ['unit' => 'new', 'currencies' => [
                ['code' => 'USD', 'cities' => ['alhasakah' => ['buy' => 1, 'sell' => 2]]],
            ]],
        ], 200)]);

        $this->expectException(\RuntimeException::class);

        app(SpTodayRateClient::class)->fetchUsdDamascusRates();
    }
}
