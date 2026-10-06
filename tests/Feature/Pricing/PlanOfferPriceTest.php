<?php

namespace Tests\Feature\Pricing;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\OutstandingItems;
use App\Domain\Finance\Payments\PaymentService;
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
use App\Domain\Pricing\Services\PriceQuoteService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Pricing\Concerns\MakesOffers;
use Tests\TestCase;

/**
 * "This package is 120, but 100 for two days": an automatic offer on a plan
 * is evaluated as of when its payment was opened and locked on it — a
 * purchase opened during the offer is honoured when it is settled after it
 * ends (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 */
class PlanOfferPriceTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

    private Plan $plan;

    private Coupon $offer;

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
        $this->offer = $this->offerOnPlan($this->plan, 100, now()->subDay(), now()->addDay());
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

    public function test_the_offer_price_holds_only_inside_its_window(): void
    {
        $quotes = app(PriceQuoteService::class);

        $this->assertSame(100, $quotes->forPlan($this->plan, null, null, now())->finalPoints);
        $this->assertSame(120, $quotes->forPlan($this->plan, null, null, now()->addDays(2))->finalPoints);
    }

    public function test_a_plan_bought_during_its_offer_costs_the_offer_price(): void
    {
        $member = $this->member(500);
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan->id])->assertCreated();

        $payment = Payment::sole();
        $this->assertSame(100, $payment->amount_points);
        $this->assertSame(120, $payment->list_price_points);

        $row = app(CouponLedger::class)->offerFor($payment);
        $this->assertSame($this->offer->id, $row->coupon_id);
        $this->assertSame(120, $row->base_points);
        $this->assertSame(20, $row->points);
        $this->assertSame(16, $row->percent);
        $this->assertSame(400, app(WalletService::class)->generalBalance(app(WalletService::class)->walletFor(OwnerType::User, $member->id)));
    }

    /** A cash hold opened during the offer is settled at the offer price after it ends. */
    public function test_a_cash_hold_opened_during_the_offer_keeps_its_price(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan->id, 'payment_method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.pending_purchase.amount_points', 100);

        $this->assertSame(100, app(OutstandingItems::class)->for($member->id, 'USD')['items'][0]['amount_points']);

        Carbon::setTestNow(now()->addDays(2));
        $operator = User::factory()->create();
        $operator->assignRole('operations');

        app(PaymentService::class)->settleAtReception(PaymentPurpose::PlanPurchase, Payment::sole(), PaymentMethod::Cash, null, null, $operator);

        $this->assertSame(100, Payment::sole()->amount_points);
        $this->assertSame(1, Membership::count());
    }

    public function test_a_card_payment_charges_the_offer_price(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
            ]),
        ]);
        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan->id, 'payment_method' => 'paymera'])->assertCreated();

        $payment = Payment::sole();
        $this->assertSame(100, $payment->amount_points);
        $this->assertSame(100, $payment->points_to_credit);
    }

    /**
     * Review Focus 3: an offer that takes the whole price makes the plan
     * free — activated at once, as a 100% coupon would.
     */
    public function test_a_plan_made_free_by_an_offer_is_activated_at_once(): void
    {
        $this->offer->delete();
        $free = Coupon::factory()->automatic()->percentage(100)->create();
        $free->targets()->create(['purpose' => PricingPurpose::PlanPurchase, 'plan_id' => $this->plan->id]);
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan->id])->assertCreated();

        $this->assertSame(1, Membership::count());
        $payment = Payment::sole();
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame(0, $payment->amount_points);
    }

    /** Review Focus 5: a payment is priced at the offer it locked, however the offer is edited. */
    public function test_a_plan_payment_is_priced_at_its_locked_offer_after_the_offer_is_edited(): void
    {
        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson('/api/v1/member/memberships', ['plan_id' => $this->plan->id, 'payment_method' => 'cash'])->assertCreated();

        $this->offer->update(['discount_value' => 50]);

        $this->assertSame(100, app(PriceQuoteService::class)->forPlanPayment(Payment::sole(), $this->plan)->finalPoints);
    }

    /** Repricing the plan itself is still caught: its list price is read afresh. */
    public function test_a_repriced_plan_without_an_offer_costs_its_new_price(): void
    {
        $this->plan->update(['price_points' => 150]);
        $this->offer->update(['is_active' => false]);

        $this->assertSame(150, app(PriceQuoteService::class)->forPlan($this->plan, null, null, now())->finalPoints);
    }
}
