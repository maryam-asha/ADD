<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A wallet top-up from the app has a floor — config
 * payments.min_top_up_points, 10 by default — told to the member in money
 * as well as points. Nothing else a member pays for has one.
 *
 * Rates chosen so the arithmetic is plain: 1 point = 0.10 USD and
 * 1 USD = 10,000 SYP, so 10 points cost 10,000.00 SYP.
 */
class MinimumTopUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-key',
            'paymera.terminal_id' => '14740460',
            'paymera.currency' => 'SYP',
            'paymera.currency_exponent' => 2,
            'payments.min_top_up_points' => 10,
        ]);

        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);

        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/ar', 'paymentId' => 'pay-1'],
            ]),
        ]);

        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        Sanctum::actingAs($member, ['member-app']);
    }

    private function topUp(string $amount)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/wallet/top-ups', [
            'payment_method' => 'paymera',
            'amount' => $amount,
        ]);
    }

    public function test_a_top_up_below_ten_points_is_refused_in_money_terms(): void
    {
        $this->topUp('9900')
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The minimum top-up is 10,000 SYP (10 points).');

        $this->assertSame(0, Payment::count());
    }

    public function test_an_amount_worth_less_than_a_point_gets_the_same_message(): void
    {
        $this->topUp('10')
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The minimum top-up is 10,000 SYP (10 points).');
    }

    public function test_the_minimum_itself_is_accepted_and_credits_ten_points(): void
    {
        $this->topUp('10000')->assertCreated();

        $this->assertSame(10, Payment::sole()->points_to_credit);
    }

    public function test_the_floor_follows_the_setting(): void
    {
        config(['payments.min_top_up_points' => 5]);

        $this->topUp('5000')->assertCreated();
    }

    public function test_payment_methods_tells_the_app_the_floor_for_a_top_up(): void
    {
        $this->getJson('/api/v1/member/payment-methods?purpose=wallet_top_up')
            ->assertOk()
            ->assertJsonPath('data.min_top_up', ['points' => 10, 'amount' => '10000', 'currency' => 'SYP']);
    }

    public function test_no_other_purpose_has_a_floor(): void
    {
        $this->getJson('/api/v1/member/payment-methods')
            ->assertOk()
            ->assertJsonPath('data.min_top_up', null);
    }

    public function test_without_an_exchange_rate_the_methods_still_list_with_no_floor(): void
    {
        ExchangeRate::query()->delete();

        $this->getJson('/api/v1/member/payment-methods?purpose=wallet_top_up')
            ->assertOk()
            ->assertJsonPath('data.min_top_up', null);
    }

    /**
     * At 1 USD = 13,840 SYP the stored rate is not exact, so 10 points come
     * to 13,840.0001 and change. The member is shown 13,840 — never
     * 13,840.01 — and paying exactly that is accepted for 10 points.
     */
    public function test_the_minimum_is_a_whole_amount_and_paying_it_credits_ten_points(): void
    {
        ExchangeRate::query()->delete();
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0000722543']);

        $this->getJson('/api/v1/member/payment-methods?purpose=wallet_top_up')
            ->assertJsonPath('data.min_top_up.amount', '13840');

        $this->topUp('13839')
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The minimum top-up is 13,840 SYP (10 points).');

        $this->topUp('13840')->assertCreated();
        $this->assertSame(10, Payment::sole()->points_to_credit);
    }
}
