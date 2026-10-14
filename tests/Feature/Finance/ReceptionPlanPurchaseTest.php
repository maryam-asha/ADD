<?php

namespace Tests\Feature\Finance;

use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\CompanyStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Services\CouponLedger;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Pricing\Concerns\MakesOffers;
use Tests\TestCase;

/**
 * Reception selling a plan for cash from the dashboard, the way it tops up
 * a wallet: the plan's price exactly — the desk types no amount — becomes
 * points in the owner's wallet and is spent on the plan at once
 * (docs/decisions/unified-payments.md, "Plans sold for cash at reception").
 */
class ReceptionPlanPurchaseTest extends TestCase
{
    use MakesOffers, RefreshDatabase;

    private User $operator;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        Space::factory()->create(['building_id' => Building::factory()->create(['branch_id' => $branch->id])->id, 'capacity' => 10]);

        $this->plan = Plan::factory()->create([
            'price_points' => 1200,
            'duration_days' => 30,
            'is_active' => true,
            'is_subscription' => true,
        ]);

        $this->operator = User::factory()->create();
        $this->operator->assignRole('operations');
        Sanctum::actingAs($this->operator, ['*']);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    private function balance(OwnerType $type, int $id): int
    {
        return app(WalletService::class)->generalBalance(app(WalletService::class)->walletFor($type, $id));
    }

    private function sell(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/plan-purchases', $body);
    }

    public function test_reception_sells_a_plan_for_its_exact_price_in_cash(): void
    {
        $member = $this->member();

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.points_charged', 1200)
            ->assertJsonPath('data.payment_amount', '120')
            ->assertJsonPath('data.payment_currency', 'USD')
            ->assertJsonPath('data.owner_type', 'user')
            ->assertJsonPath('data.owner_id', $member->id)
            ->assertJsonPath('data.performed_by_name', $this->operator->name)
            ->assertJsonPath('data.membership.plan_id', $this->plan->id);

        $membership = Membership::sole();
        $this->assertSame(OwnerType::User, $membership->owner_type);
        $this->assertSame($member->id, (int) $membership->owner_id);

        $payment = Payment::sole();
        $this->assertSame('cash', $payment->method);
        $this->assertSame(PaymentPurpose::PlanPurchase, $payment->purpose);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertSame(PaymentMethod::Cash, $payment->collected_via);
        $this->assertSame($this->operator->id, $payment->received_by);
        $this->assertSame($membership->id, $payment->membership_id);
        $this->assertSame($member->id, $payment->user_id);

        $this->assertSame(1200, (int) WalletTransaction::findOrFail($payment->wallet_transaction_id)->amount);
        $this->assertSame(-1200, (int) WalletTransaction::findOrFail($payment->debit_transaction_id)->amount);
        $this->assertSame(0, $this->balance(OwnerType::User, $member->id));

        $this->assertTrue(Activity::where('description', 'plan_sold_at_reception')->where('causer_id', $this->operator->id)->exists());
    }

    public function test_the_amount_is_the_price_in_the_currency_chosen_and_cannot_be_typed(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);
        $member = $this->member();

        // $120 at 0.0001 = 1,200,000 SYP. An `amount` sent is ignored.
        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash', 'currency' => 'SYP', 'amount' => '5000000'])
            ->assertCreated()
            ->assertJsonPath('data.payment_currency', 'SYP')
            ->assertJsonPath('data.points_charged', 1200);

        $payment = Payment::sole();
        $this->assertSame('SYP', $payment->received_currency);
        $this->assertSame(0, bccomp('1200000', (string) $payment->received_amount, 2));
        $this->assertSame(0, $this->balance(OwnerType::User, $member->id));
    }

    public function test_a_plan_is_sold_to_a_company_on_its_own_wallet(): void
    {
        $company = Company::factory()->create([
            'status' => CompanyStatus::Active,
            'branch_id' => Branch::query()->where('is_default', true)->value('id'),
        ]);

        $this->sell(['plan_id' => $this->plan->id, 'company_id' => $company->id, 'payment_method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.owner_type', 'company')
            ->assertJsonPath('data.recipient_name', $company->legal_name);

        $membership = Membership::sole();
        $this->assertSame(OwnerType::Company, $membership->owner_type);
        $this->assertSame($company->id, (int) $membership->owner_id);

        $payment = Payment::sole();
        $this->assertSame(OwnerType::Company, $payment->wallet->owner_type);
        $this->assertSame($this->operator->id, $payment->user_id);
        $this->assertSame(0, $this->balance(OwnerType::Company, $company->id));
    }

    public function test_an_inactive_company_is_refused(): void
    {
        $company = Company::factory()->create(['status' => CompanyStatus::Inactive]);

        $this->sell(['plan_id' => $this->plan->id, 'company_id' => $company->id, 'payment_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('company_id');

        $this->assertSame(0, Payment::count());
    }

    public function test_an_owner_with_a_live_membership_is_refused_and_nothing_is_written(): void
    {
        $member = $this->member();
        Membership::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.admin.membership_already_active', [], 'en'));

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_an_inactive_or_one_time_plan_is_refused(): void
    {
        $member = $this->member();
        $inactive = Plan::factory()->create(['is_active' => false, 'is_subscription' => true]);
        $oneTime = Plan::factory()->create(['is_active' => true, 'is_subscription' => false]);

        $this->sell(['plan_id' => $inactive->id, 'user_id' => $member->id, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_id');
        $this->sell(['plan_id' => $oneTime->id, 'user_id' => $member->id, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('plan_id');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_full_branch_records_nothing(): void
    {
        Space::query()->update(['capacity' => 1]);
        Membership::factory()->create(['branch_id' => Branch::query()->where('is_default', true)->value('id')]);
        $member = $this->member();

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.membership.seat_pool_full', [], 'en'));

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, WalletTransaction::count());
        $this->assertSame(1, Membership::count());
    }

    public function test_a_running_offer_prices_the_sale_as_it_does_in_the_app(): void
    {
        $this->offerOnPlan($this->plan, 1000, now()->subDay(), now()->addDay());
        $member = $this->member();

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash'])
            ->assertCreated()
            ->assertJsonPath('data.points_charged', 1000)
            ->assertJsonPath('data.payment_amount', '100');

        $this->assertSame(1200, (int) Payment::sole()->list_price_points);
        $this->assertSame(0, $this->balance(OwnerType::User, $member->id));
    }

    public function test_the_preview_quotes_the_price_without_writing_anything(): void
    {
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);
        $member = $this->member();

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/plan-purchases/preview', [
            'plan_id' => $this->plan->id,
            'user_id' => $member->id,
            'payment_method' => 'cash',
            'currency' => 'SYP',
        ])
            ->assertOk()
            ->assertJsonPath('data.points', 1200)
            ->assertJsonPath('data.list_points', 1200)
            ->assertJsonPath('data.payment_currency', 'SYP')
            ->assertJsonPath('data.balance_points', 0)
            ->assertJsonPath('data.wallet_covers', false);

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Membership::count());
    }

    public function test_the_preview_says_when_the_points_cover_the_plan(): void
    {
        $member = $this->member();
        $this->credit(OwnerType::User, $member->id, 1500);

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/plan-purchases/preview', [
            'plan_id' => $this->plan->id,
            'user_id' => $member->id,
            'payment_method' => 'wallet',
        ])
            ->assertOk()
            ->assertJsonPath('data.balance_points', 1500)
            ->assertJsonPath('data.wallet_covers', true);
    }

    private function credit(OwnerType $type, int $id, int $points): void
    {
        app(WalletService::class)->creditGeneral(
            Wallet::query()->firstOrCreate(['owner_type' => $type, 'owner_id' => $id]),
            $points,
            WalletTransactionSource::TopUp,
        );
    }

    public function test_reception_sells_a_plan_from_the_members_points_when_they_cover_it(): void
    {
        $member = $this->member();
        $this->credit(OwnerType::User, $member->id, 1500);

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'wallet')
            ->assertJsonPath('data.points_charged', 1200)
            ->assertJsonPath('data.points_credited', 0)
            ->assertJsonPath('data.payment_amount', null)
            ->assertJsonPath('data.previous_balance_points', 1500)
            ->assertJsonPath('data.new_balance_points', 300);

        $payment = Payment::sole();
        $this->assertSame('wallet', $payment->method);
        $this->assertSame(PaymentStatus::Accepted, $payment->status);
        $this->assertNull($payment->wallet_transaction_id);
        $this->assertNull($payment->collected_via);
        $this->assertSame(-1200, (int) WalletTransaction::findOrFail($payment->debit_transaction_id)->amount);
        $this->assertSame(Membership::sole()->id, $payment->membership_id);
        $this->assertSame(300, $this->balance(OwnerType::User, $member->id));
    }

    public function test_points_that_do_not_cover_the_plan_are_refused_with_the_shortage(): void
    {
        $member = $this->member();
        $this->credit(OwnerType::User, $member->id, 1000);

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.payment.insufficient_points', ['current' => 1000, 'required' => 1200, 'shortage' => 200], 'en'))
            ->assertJsonPath('current', 1000)
            ->assertJsonPath('required', 1200)
            ->assertJsonPath('shortage', 200);

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Membership::count());
        $this->assertSame(1000, $this->balance(OwnerType::User, $member->id));
    }

    public function test_a_company_plan_is_paid_from_the_companys_points(): void
    {
        $company = Company::factory()->create([
            'status' => CompanyStatus::Active,
            'branch_id' => Branch::query()->where('is_default', true)->value('id'),
        ]);
        $this->credit(OwnerType::Company, $company->id, 1200);

        $this->sell(['plan_id' => $this->plan->id, 'company_id' => $company->id, 'payment_method' => 'wallet'])
            ->assertCreated()
            ->assertJsonPath('data.owner_type', 'company');

        $this->assertSame(OwnerType::Company, Membership::sole()->owner_type);
        $this->assertSame(0, $this->balance(OwnerType::Company, $company->id));
    }

    public function test_points_ignore_a_currency_sent_with_them(): void
    {
        $member = $this->member();
        $this->credit(OwnerType::User, $member->id, 1200);

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'wallet', 'currency' => 'USD'])
            ->assertCreated()
            ->assertJsonPath('data.payment_currency', null);
    }

    private function preview(array $body)
    {
        return $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/plan-purchases/preview', $body);
    }

    public function test_the_preview_names_the_running_offer_and_what_it_saves(): void
    {
        $ends = now()->addDay()->startOfMinute();
        $this->offerOnPlan($this->plan, 1000, now()->subDay(), $ends);
        $member = $this->member();

        $this->preview(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.points', 1000)
            ->assertJsonPath('data.offer.name', 'Offer')
            ->assertJsonPath('data.offer.discount_type', 'amount_off')
            ->assertJsonPath('data.offer.discount_value', 200)
            ->assertJsonPath('data.offer.saved_points', 200)
            ->assertJsonPath('data.offer.ends_at', $ends->toIso8601String())
            ->assertJsonPath('data.coupon', null);
    }

    public function test_no_offer_previews_as_null(): void
    {
        $this->preview(['plan_id' => $this->plan->id, 'user_id' => $this->member()->id, 'payment_method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.offer', null);
    }

    public function test_a_coupon_the_member_brings_prices_the_preview_and_the_sale(): void
    {
        $coupon = Coupon::factory()->percentage(50)->create(['code' => 'HALF']);
        $member = $this->member();
        $body = ['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash', 'coupon_code' => 'half'];

        $this->preview($body)
            ->assertOk()
            ->assertJsonPath('data.points', 600)
            ->assertJsonPath('data.payment_amount', '60')
            ->assertJsonPath('data.coupon.code', 'HALF')
            ->assertJsonPath('data.coupon.applied', true)
            ->assertJsonPath('data.coupon.points_discounted', 600);
        $this->assertSame(0, app(CouponLedger::class)->liveUsage($coupon));

        $this->sell($body)
            ->assertCreated()
            ->assertJsonPath('data.points_charged', 600)
            ->assertJsonPath('data.payment_amount', '60')
            ->assertJsonPath('data.coupon.applied', true)
            ->assertJsonPath('data.discounts.0.kind', 'coupon')
            ->assertJsonPath('data.discounts.0.code', 'HALF')
            ->assertJsonPath('data.discounts.0.points', 600);

        $this->assertSame(1, app(CouponLedger::class)->liveUsage($coupon));
        $this->assertSame(0, $this->balance(OwnerType::User, $member->id));
    }

    public function test_a_coupon_also_prices_a_sale_paid_from_points(): void
    {
        Coupon::factory()->percentage(50)->create(['code' => 'HALF']);
        $member = $this->member();
        $this->credit(OwnerType::User, $member->id, 700);

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'wallet', 'coupon_code' => 'HALF'])
            ->assertCreated()
            ->assertJsonPath('data.points_charged', 600);

        $this->assertSame(100, $this->balance(OwnerType::User, $member->id));
    }

    public function test_an_unknown_coupon_is_refused_with_its_reason_and_nothing_is_written(): void
    {
        $member = $this->member();
        $body = ['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash', 'coupon_code' => 'NOPE'];

        $this->preview($body)->assertStatus(422)->assertJsonMissingPath('reason')->assertJsonPath('message', __('api.coupon.not_found'));
        $this->sell($body)->assertStatus(422)->assertJsonMissingPath('reason')->assertJsonPath('message', __('api.coupon.not_found'));

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Membership::count());
    }

    public function test_a_coupon_is_not_taken_on_a_company_sale(): void
    {
        Coupon::factory()->create(['code' => 'HALF']);
        $company = Company::factory()->create(['status' => CompanyStatus::Active]);

        $this->sell(['plan_id' => $this->plan->id, 'company_id' => $company->id, 'payment_method' => 'cash', 'coupon_code' => 'HALF'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('coupon_code');
    }

    public function test_a_member_cannot_sell_plans(): void
    {
        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        $this->sell(['plan_id' => $this->plan->id, 'user_id' => $member->id, 'payment_method' => 'cash'])
            ->assertForbidden();
    }
}
