<?php

namespace Tests\Unit\Domain\Finance;

use App\Domain\Finance\Exceptions\InvalidMoneyAmountException;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Money handed over (at the desk, or by any other method that is not points)
 * becomes points in exactly one place, in bcmath. Credits floor; the money
 * quoted for a number of points rounds up — so the two directions always
 * meet: paying `pointsToAmount($n)` buys at least `$n` points, never fewer.
 */
class ReceivedMoneyConverterTest extends TestCase
{
    use RefreshDatabase;

    private const SYP_RATE = '0.0000680272';

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingService::class)->setDefault('point.rate_usd', '0.10', SettingValueType::String);
    }

    private function converter(): ReceivedMoneyConverter
    {
        return app(ReceivedMoneyConverter::class);
    }

    public function test_base_currency_money_converts_without_a_rate(): void
    {
        $converted = $this->converter()->toPoints('10.05', 'USD');

        // $10.05 buys 100 whole points; the spare 5 cents buy nothing.
        $this->assertSame(100, $converted->points);
        $this->assertSame('10.05', $converted->baseAmount);
        $this->assertNull($converted->rateToBase);
        $this->assertSame('0.10', $converted->pointRateUsd);

        $this->assertSame('10.00', $this->converter()->pointsToAmount(100, 'USD'));
    }

    /**
     * The point rate is validated to four decimal places, so a point can be
     * worth a fraction of a cent. The quote still has to buy the points back.
     */
    public function test_a_base_currency_quote_rounds_up_to_the_cent(): void
    {
        app(SettingService::class)->set('point.rate_usd', '0.125', SettingValueType::String);

        // 3 points = $0.375, which no one can hand over: it is $0.38.
        $this->assertSame('0.38', $this->converter()->pointsToAmount(3, 'USD'));
        $this->assertSame(3, $this->converter()->toPoints('0.38', 'USD')->points);
        $this->assertSame(2, $this->converter()->toPoints('0.37', 'USD')->points);
    }

    public function test_syrian_money_converts_at_the_current_rate_rounding_points_down(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => self::SYP_RATE]);

        $converted = $this->converter()->toPoints('500000', 'SYP');

        // 500,000 x 0.0000680272 = $34.0136 -> 340.136 points -> floor -> 340.
        $this->assertSame(340, $converted->points);
        $this->assertSame('34.01', $converted->baseAmount);
        $this->assertSame(self::SYP_RATE, $converted->rateToBase);
        $this->assertSame('0.10', $converted->pointRateUsd);
    }

    public function test_the_syrian_amount_quoted_for_points_rounds_up_and_buys_them_back(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => self::SYP_RATE]);

        // 340 points = $34.00 = 499,800.07996... SYP -> up to a whole pound:
        // the pound has no piasters in use (decimal_places = 0).
        $amount = $this->converter()->pointsToAmount(340, 'SYP');

        $this->assertSame('499801', $amount);

        // Enough: the quote buys the points, the whole pound below does not.
        $this->assertSame(340, $this->converter()->toPoints($amount, 'SYP')->points);
        $this->assertSame(339, $this->converter()->toPoints('499800', 'SYP')->points);
    }

    /**
     * The same vectors the dashboard's top-up-math.spec.ts asserts, so the
     * dashboard's quote and the server's verification of it are proven to
     * agree (spec 2026-10-10 §6). Each new rate row is newer than the last
     * (higher id, same effective_from), and saving one clears
     * ExchangeRate::current()'s cache.
     */
    public function test_to_points_floors_the_exact_value_once(): void
    {
        $vectors = [
            // [amount, currency, rate_to_base (null = base currency), expected points]
            ['500000', 'SYP', '0.0000680272', 340],
            ['501200', 'SYP', '0.0000680272', 340], // rounding to cents first credited 341
            ['150000.00', 'SYP', '0.0000666667', 100],
            ['5000', 'SYP', '0.0068027211', 340],   // new pound
            ['0.30', 'USD', null, 3],               // float division gives 2
            ['10.05', 'USD', null, 100],
        ];

        foreach ($vectors as [$amount, $currency, $rate, $expected]) {
            if ($rate !== null) {
                ExchangeRate::factory()->create(['currency_code' => $currency, 'rate_to_base' => $rate]);
            }

            $this->assertSame($expected, $this->converter()->toPoints($amount, $currency)->points, "{$amount} {$currency}");
        }
    }

    public function test_a_currency_with_no_rate_cannot_be_converted(): void
    {
        // SYP exists (seeded by its migration) but nobody has entered a rate.
        $this->expectException(PaymentPricingException::class);

        $this->converter()->toPoints('500000', 'SYP');
    }

    public function test_a_currency_with_no_rate_cannot_be_quoted(): void
    {
        $this->expectException(PaymentPricingException::class);

        $this->converter()->pointsToAmount(340, 'SYP');
    }

    /**
     * Only a plain decimal is money here (final review M4). Every other
     * spelling `numeric` would let through — an exponent, a sign, padding, a
     * bare point — is refused as a domain error (422), never handed to
     * bcmath, whose ValueError would be a 500.
     */
    public function test_only_a_plain_decimal_is_converted(): void
    {
        foreach (['1000', '1000.000'] as $amount) {
            $this->assertSame(10000, $this->converter()->toPoints($amount, 'USD')->points, $amount);
        }

        foreach (['1e3', '1e0', '1.0E+2', ' 1000 ', ' 5', '1000.', '+1000', '.5', '-5', 'ten dollars', ''] as $amount) {
            try {
                $this->converter()->toPoints($amount, 'USD');
                $this->fail("[{$amount}] was converted.");
            } catch (InvalidMoneyAmountException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
