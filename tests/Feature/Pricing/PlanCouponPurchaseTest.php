<?php

namespace Tests\Feature\Pricing;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A coupon on a plan, by every way a plan is bought
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 * The coupon is locked on the payment when it is opened; a hold that
 * expires or a card that fails gives the use back.
 */
class PlanCouponPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

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

        $this->plan = Plan::factory()->create([
            'price_points' => 120,
            'duration_days' => 30,
            'is_active' => true,
            'is_public' => true,
            'is_subscription' => true,
        ]);
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function balance(User $member): int
    {
        return app(WalletService::class)->generalBalance(app(WalletService::class)->walletFor(OwnerType::User, $member->id));
    }

    private function buy(string $code, ?string $method = null, array $extra = [])
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/memberships', array_filter([
            'plan_id' => $this->plan->id,
            'payment_method' => $method,
            'coupon_code' => $code,
        ] + $extra));
    }

    public function test_a_plan_bought_with_points_and_a_coupon(): void
    {
        $coupon = Coupon::factory()->percentage(50)->create(['code' => 'HALF']);
        $member = $this->member(500);

        $this->buy('half')
            ->assertCreated()
            ->assertJsonPath('coupon.code', 'HALF')
            ->assertJsonPath('coupon.applied', true)
            ->assertJsonPath('coupon.points_discounted', 60);

        $payment = Payment::sole();
        $this->assertSame(60, $payment->amount_points);
        $this->assertSame(120, $payment->list_price_points);
        $this->assertSame(440, $this->balance($member));
        $this->assertSame(1, Membership::count());
        $this->assertSame($payment->id, app(CouponLedger::class)->couponFor($payment)->payment_id);
        $this->assertSame(1, app(CouponLedger::class)->liveUsage($coupon));
    }

    public function test_a_coupon_on_top_of_the_plans_sale(): void
    {
        $this->plan->update(['sale_price_points' => 100, 'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay()]);
        Coupon::factory()->percentage(10)->create(['code' => 'TEN']);
        $this->member(500);

        $this->buy('TEN')->assertCreated();

        $this->assertSame(90, Payment::sole()->amount_points);
    }

    /** A cash hold carries the coupon to the desk; letting it expire gives the use back. */
    public function test_a_cash_hold_carries_the_coupon_until_it_is_settled_or_expires(): void
    {
        $coupon = Coupon::factory()->percentage(50)->create(['code' => 'HALF']);
        $this->member();

        $this->buy('HALF', 'cash')
            ->assertCreated()
            ->assertJsonPath('data.pending_purchase.amount_points', 60)
            ->assertJsonPath('coupon.points_discounted', 60);

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        app(PaymentService::class)->settleAtReception(PaymentPurpose::PlanPurchase, Payment::sole(), PaymentMethod::Cash, null, null, $operator);
        $this->assertSame(60, Payment::sole()->amount_points);
        $this->assertSame(1, Membership::count());

        // A second member's hold, left to expire.
        $this->member();
        $this->buy('HALF', 'cash')->assertCreated();
        $this->assertSame(2, app(CouponLedger::class)->liveUsage($coupon));

        Payment::query()->latest('id')->firstOrFail()->forceFill(['status' => PaymentStatus::Expired])->save();
        $this->assertSame(1, app(CouponLedger::class)->liveUsage($coupon));
    }

    public function test_a_card_payment_is_opened_at_the_coupon_price(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
            ]),
        ]);
        $coupon = Coupon::factory()->percentage(50)->create(['code' => 'HALF']);
        $this->member();

        $this->buy('HALF', 'paymera')
            ->assertCreated()
            ->assertJsonPath('data.next', 'redirect')
            ->assertJsonPath('coupon.points_discounted', 60);

        $payment = Payment::sole();
        $this->assertSame(60, $payment->amount_points);
        $this->assertSame(60, $payment->points_to_credit);
        $this->assertSame(600, $payment->amount_minor);
        $this->assertSame(1, app(CouponLedger::class)->liveUsage($coupon));

        $payment->forceFill(['status' => PaymentStatus::Failed])->save();
        $this->assertSame(0, app(CouponLedger::class)->liveUsage($coupon));
    }

    /** A coupon that takes the whole price activates the plan at once, by any method. */
    public function test_a_free_plan_is_activated_at_once(): void
    {
        Coupon::factory()->percentage(100)->create(['code' => 'GIFT']);

        foreach ([null, 'cash', 'paymera'] as $method) {
            $member = $this->member();

            $this->buy('GIFT', $method)->assertCreated();

            $this->assertTrue(Membership::query()->where('owner_type', OwnerType::User)->where('owner_id', $member->id)->exists(), "method {$method}");
            $payment = Payment::query()->where('user_id', $member->id)->sole();
            $this->assertSame(PaymentStatus::Accepted, $payment->status);
            $this->assertSame(0, $payment->amount_points);
            $this->assertNotNull($payment->settled_at);
        }
    }

    public function test_a_coupon_is_refused_on_a_company_purchase(): void
    {
        Coupon::factory()->create(['code' => 'HALF']);
        $member = $this->member(500);
        $company = Company::factory()->create();
        $company->members()->attach($member, ['is_admin' => true]);
        $wallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($wallet, 500, WalletTransactionSource::TopUp);

        $this->buy('HALF', null, ['company_id' => $company->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['coupon_code']);

        $this->assertSame(0, Payment::count());
    }

    public function test_a_coupon_for_another_plan_is_refused_with_its_reason(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'OTHER']);
        $coupon->targets()->create(['purpose' => PricingPurpose::PlanPurchase, 'plan_id' => Plan::factory()->create()->id]);
        $this->member(500);

        $this->buy('OTHER')
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.coupon.not_applicable', [], 'en'));

        $this->assertSame(0, Payment::count());
    }

    /**
     * A coupon that does not combine with offers, beaten by the sale: the
     * plan is bought at the sale price and the response says why the code
     * was not used (D2) — the use is not spent.
     */
    public function test_a_coupon_beaten_by_the_sale_says_so(): void
    {
        $this->plan->update(['sale_price_points' => 100, 'sale_starts_at' => now()->subDay(), 'sale_ends_at' => now()->addDay()]);
        $coupon = Coupon::factory()->percentage(10)->create(['code' => 'SOLO', 'combinable_with_offers' => false]);
        $this->member(500);

        $this->buy('solo')
            ->assertCreated()
            ->assertJsonPath('coupon.code', 'SOLO')
            ->assertJsonPath('coupon.applied', false)
            ->assertJsonPath('coupon.points_discounted', 0)
            ->assertJsonPath('coupon.message', __('api.coupon.offer_is_better', [], 'en'));

        $this->assertSame(100, Payment::sole()->amount_points);
        $this->assertSame(0, app(CouponLedger::class)->liveUsage($coupon));
    }

    public function test_the_payments_endpoint_takes_a_coupon_for_a_plan(): void
    {
        Coupon::factory()->amountOff(20)->create(['code' => 'TWENTY']);
        $member = $this->member(500);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/payments', [
            'purpose' => 'plan_purchase',
            'method' => 'wallet',
            'plan_id' => $this->plan->id,
            'coupon_code' => 'TWENTY',
        ])->assertCreated()->assertJsonPath('coupon.points_discounted', 20);

        $this->assertSame(100, Payment::sole()->amount_points);
        $this->assertSame(400, $this->balance($member));
    }
}
