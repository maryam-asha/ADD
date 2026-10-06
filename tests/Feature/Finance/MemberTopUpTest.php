<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\Methods\PaymeraGateway;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST member/wallet/top-ups — a member adds points to their own wallet, by a
 * prepaid method only. Cash for points is reception's
 * (admin/reception/wallet-top-ups), never the app's.
 *
 * Rates chosen so the arithmetic is plain: 1 point = 0.10 USD and
 * 1 USD = 10,000 SYP, so 10 points cost 10,000.00 SYP.
 */
class MemberTopUpTest extends TestCase
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

    private function topUp(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/wallet/top-ups', $body);
    }

    public function test_a_top_up_opens_a_card_payment(): void
    {
        $this->topUp(['amount' => '10000'])
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-1/ar')
            ->assertJsonPath('data.payment.status', 'pending');
    }

    public function test_cash_is_reception_only(): void
    {
        $this->topUp(['amount' => '10000', 'payment_method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Payment::count());
    }

    public function test_points_cannot_buy_points(): void
    {
        $this->topUp(['amount' => '10000', 'payment_method' => 'wallet'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');
    }

    /**
     * StartPayment opens Paymera only: a second gateway would be accepted
     * here and charged through Paymera, so it is refused until StartPayment
     * takes the method's key.
     */
    public function test_only_the_gateway_the_top_up_charges_is_accepted(): void
    {
        config(['payments.methods.other_card' => ['driver' => PaymeraGateway::class, 'enabled' => true]]);

        $this->topUp(['amount' => '10000', 'payment_method' => 'other_card'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Payment::count());

        $this->topUp(['amount' => '10000', 'payment_method' => 'paymera'])->assertCreated();
    }

    public function test_the_minimum_is_named_in_money(): void
    {
        $this->topUp(['amount' => '9900'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.amount.0', 'The minimum top-up is 10,000 SYP (10 points).');
    }

    public function test_an_amount_is_required(): void
    {
        $this->topUp([])->assertUnprocessable()->assertJsonValidationErrors('amount');
    }

    public function test_the_payment_methods_for_a_top_up_are_prepaid_only(): void
    {
        $keys = array_column($this->getJson('/api/v1/member/payment-methods?purpose=wallet_top_up')->json('data.methods'), 'key');

        $this->assertSame(['paymera'], $keys);
    }
}
