<?php

namespace Tests\Feature\Console;

use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class FetchExchangeRateSuggestionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function fakeBody(int $sell, int $buy): array
    {
        return [
            'ok' => true,
            'data' => ['currencies' => [
                ['code' => 'USD', 'cities' => ['damascus' => ['buy' => $buy, 'sell' => $sell]]],
            ]],
        ];
    }

    public function test_a_successful_response_creates_a_pending_suggestion_with_the_exact_sell_price(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(13275, 13225), 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseHas('exchange_rate_suggestions', [
            'rate_usd_to_syp' => '13275.0000000000',
            'status' => ExchangeRateSuggestionStatus::Pending->value,
            'source' => 'sp_today',
        ]);
    }

    public function test_sell_below_buy_creates_no_suggestion_and_logs_an_error(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(13000, 13225), 200)]);
        Log::spy();

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_a_missing_sell_field_creates_no_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response([
            'ok' => true,
            'data' => ['currencies' => [['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225]]]]],
        ], 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_non_numeric_sell_field_creates_no_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response([
            'ok' => true,
            'data' => ['currencies' => [['code' => 'USD', 'cities' => ['damascus' => ['buy' => 13225, 'sell' => 'not-a-number']]]]],
        ], 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_zero_sell_field_creates_no_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response($this->fakeBody(0, 0), 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_non_numeric_buy_field_creates_no_suggestion(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response([
            'ok' => true,
            'data' => ['currencies' => [['code' => 'USD', 'cities' => ['damascus' => ['sell' => 13275, 'buy' => 'not-a-number']]]]],
        ], 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_network_failure_creates_no_suggestion_and_does_not_throw(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => fn () => throw new ConnectionException('timed out')]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_401_response_creates_no_suggestion_and_does_not_throw(): void
    {
        Http::fake(['api-v2.sp-today.com/*' => Http::response('', 401)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $this->assertDatabaseCount('exchange_rate_suggestions', 0);
    }

    public function test_a_second_successful_fetch_supersedes_the_first_pending_suggestion(): void
    {
        $callCount = 0;
        Http::fake(['api-v2.sp-today.com/*' => function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                return Http::response($this->fakeBody(13275, 13225), 200);
            } else {
                return Http::response($this->fakeBody(13400, 13350), 200);
            }
        }]);

        $this->artisan('finance:fetch-exchange-rate-suggestion');
        $first = ExchangeRateSuggestion::sole();

        $this->artisan('finance:fetch-exchange-rate-suggestion');

        $this->assertSame(ExchangeRateSuggestionStatus::Superseded, $first->refresh()->status);
        $this->assertSame(1, ExchangeRateSuggestion::where('status', ExchangeRateSuggestionStatus::Pending)->count());
        $this->assertSame('13400.0000000000', ExchangeRateSuggestion::where('status', ExchangeRateSuggestionStatus::Pending)->sole()->rate_usd_to_syp);
    }
}
