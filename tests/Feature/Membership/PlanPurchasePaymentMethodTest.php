<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
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
 * Buying a plan by any method (docs/superpowers/plans/2026-09-30-unified-payments.md,
 * Task 6): points now (the default, as before), cash at the desk — held as
 * a pending purchase until reception takes the money — or a card.
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

    public function test_a_plan_bought_with_cash_is_held_for_reception_without_a_membership(): void
    {
        $member = $this->member(0);
        $plan = $this->plan();
        Sanctum::actingAs($member, ['*']);

        $response = $this->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id, 'payment_method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.pending_purchase.method', 'cash')
            ->assertJsonPath('data.pending_purchase.amount_points', 1200);

        $this->assertSame(0, Membership::count());
        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame($plan->id, $payment->plan_id);
        $this->assertTrue($payment->expires_at->equalTo(now()->addHours(48)));
        $this->assertSame($payment->id, $response->json('data.pending_purchase.id'));
    }

    public function test_asking_again_for_the_same_plan_with_cash_returns_the_same_hold(): void
    {
        $member = $this->member(0);
        $plan = $this->plan();
        Sanctum::actingAs($member, ['*']);

        $first = $this->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id, 'payment_method' => 'cash'])
            ->assertCreated()->json('data.pending_purchase.id');
        $second = $this->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id, 'payment_method' => 'cash'])
            ->assertCreated()->json('data.pending_purchase.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Payment::count());
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
            ->assertJsonPath('data.payment.method', 'paymera');

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

    public function test_the_current_membership_shows_a_pending_cash_purchase(): void
    {
        $member = $this->member(0);
        $plan = $this->plan();
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/member/memberships/current')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('pending_purchase', null);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id, 'payment_method' => 'cash'])->assertCreated();

        $this->getJson('/api/v1/member/memberships/current')
            ->assertOk()
            ->assertJsonPath('data', null)
            ->assertJsonPath('pending_purchase.plan_id', $plan->id)
            ->assertJsonPath('pending_purchase.status', 'pending');
    }
}
