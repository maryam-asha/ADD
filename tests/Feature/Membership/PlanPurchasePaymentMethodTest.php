<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Buying a plan (docs/decisions/unified-payments.md): points now (the
 * default), or a card — subscribed only once the card is accepted. There is
 * no subscription without payment, so a plan takes no cash.
 */
class PlanPurchasePaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Damascus')->utc());

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        return $member;
    }

    private function plan(int $price = 1200): Plan
    {
        return Plan::factory()->create([
            'price_points' => $price,
            'duration_days' => 30,
            'is_active' => true,
            'is_public' => true,
            'is_subscription' => true,
        ]);
    }

    private function balance(OwnerType $type, int $id): int
    {
        return app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', $type)->where('owner_id', $id)->firstOrFail()
        );
    }

    public function test_a_plan_paid_by_wallet_still_returns_the_membership_and_now_has_a_payment(): void
    {
        $member = $this->member(5000);
        $plan = $this->plan();
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id])
            ->assertCreated()
            ->assertJsonPath('data.plan_id', $plan->id);

        $membership = Membership::sole();
        $payment = Payment::sole();
        $this->assertSame('wallet', $payment->method);
        $this->assertSame(PaymentPurpose::PlanPurchase, $payment->purpose);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame($membership->id, $payment->membership_id);
        $this->assertSame(3800, $this->balance(OwnerType::User, $member->id));
    }

    public function test_a_plan_the_wallet_cannot_cover_keeps_its_old_error(): void
    {
        $member = $this->member(100);
        Sanctum::actingAs($member, ['*']);

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan()->id, 'payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.wallet.insufficient_balance_for_plan', [], 'en'));

        $this->assertSame(0, Membership::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_company_purchase_by_wallet_debits_the_company(): void
    {
        $company = Company::factory()->create();
        Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $company->branch_id])->id,
            'capacity' => 10,
        ]);
        $companyWallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($companyWallet, 5000, WalletTransactionSource::TopUp);

        $admin = $this->member(0);
        $company->members()->attach($admin->id, ['is_admin' => true]);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan()->id, 'company_id' => $company->id])
            ->assertCreated();

        $membership = Membership::sole();
        $this->assertSame(OwnerType::Company, $membership->owner_type);
        $this->assertSame(3800, $this->balance(OwnerType::Company, $company->id));
        $this->assertSame($companyWallet->id, Payment::sole()->wallet_id);
    }

    public function test_a_plan_cannot_be_bought_with_cash(): void
    {
        $member = $this->member(0);
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan()->id, 'payment_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('payment_method');

        $this->assertSame(0, Membership::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_cash_is_not_offered_for_a_plan(): void
    {
        $member = $this->member(5000);
        Sanctum::actingAs($member, ['*']);

        $keys = collect($this->getJson('/api/v1/member/payment-methods?purpose=plan_purchase&plan_id='.$this->plan()->id)
            ->assertOk()
            ->json('data.methods'))->pluck('key')->all();

        $this->assertNotContains('cash', $keys);
        $this->assertContains('wallet', $keys);
    }

    public function test_a_plan_bought_by_card_returns_where_to_pay(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'url' => 'https://egate-t.paymera.test/start/pay-777/en',
                    'paymentId' => 'pay-777',
                ],
            ]),
        ]);

        $member = $this->member(0);
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan()->id, 'payment_method' => 'paymera'])
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-777/en')
            ->assertJsonPath('data.payment.method', 'paymera')
            ->assertJsonPath('data.payment.gateway_payment_id', 'pay-777');

        $this->assertSame(0, Membership::count());
    }

    public function test_a_company_plan_cannot_be_bought_by_card(): void
    {
        $company = Company::factory()->create();
        $admin = $this->member(0);
        $company->members()->attach($admin->id, ['is_admin' => true]);
        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/memberships', [
                'plan_id' => $this->plan()->id,
                'company_id' => $company->id,
                'payment_method' => 'paymera',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.payment.method_not_supported', [], 'en'));

        $this->assertSame(0, Payment::count());
    }

    public function test_a_plan_is_subscribed_once_its_card_payment_is_accepted(): void
    {
        $this->fakeCard('A');
        $member = $this->member(0);
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan()->id, 'payment_method' => 'paymera'])
            ->assertCreated();
        $this->assertSame(0, Membership::count());

        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame(Membership::sole()->id, $payment->membership_id);
        $this->assertSame(0, $this->balance(OwnerType::User, $member->id));
    }

    public function test_a_plan_whose_card_payment_fails_is_not_subscribed(): void
    {
        $this->fakeCard('F');
        $member = $this->member(0);
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan()->id, 'payment_method' => 'paymera'])
            ->assertCreated();

        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $this->assertSame(PaymentStatus::Failed, Payment::sole()->status);
        $this->assertSame(0, Membership::count());
        $this->assertSame(0, $this->balance(OwnerType::User, $member->id));
    }

    /**
     * A cash hold opened before plans stopped taking cash still shows until
     * reception settles it or it expires.
     */
    public function test_the_current_membership_still_shows_a_cash_hold_opened_earlier(): void
    {
        $member = $this->member(0);
        $plan = $this->plan();
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/member/memberships/current')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('pending_purchase', null);

        Payment::factory()->forPlan($plan)->withoutGateway('cash', 1200)->create([
            'user_id' => $member->id,
            'wallet_id' => Wallet::where('owner_id', $member->id)->firstOrFail()->id,
            'status' => PaymentStatus::Pending,
            'expires_at' => now()->addHours(48),
        ]);

        $this->getJson('/api/v1/member/memberships/current')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('pending_purchase.plan_id', $plan->id)
            ->assertJsonPath('pending_purchase.status', 'pending');
    }

    /** Paymera answering a plan's card: opened, then `$status` for the amount it was opened at. */
    private function fakeCard(string $status): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-777/en', 'paymentId' => 'pay-777'],
            ]),
            '*/api/get-payment-status/*' => fn () => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => $status,
                    'creationTimestamp' => '2026-10-05 10:00:00.000',
                    'rrn' => '000009876543',
                    'amount' => Payment::sole()->amount_minor,
                    'terminalId' => '99990001',
                ],
            ]),
        ]);
    }
}
