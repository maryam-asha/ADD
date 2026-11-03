<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\AllowanceCycle;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanAllowanceService;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A member holds one personal plan at a time. The only way to a second is
 * a plan whose hours are spent this cycle — the new one then replaces it.
 * A company's plan never stands in the way (product owner, 2026-11-03).
 */
class OnePersonalPlanTest extends TestCase
{
    use RefreshDatabase;

    private Plan $next;

    private Space $seats;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        $this->seats = Space::factory()->create(['building_id' => Building::factory()->create(['branch_id' => $branch->id])->id, 'capacity' => 10]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->next = Plan::factory()->create(['price_points' => 1000, 'duration_days' => 30, 'is_subscription' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_member_with_an_unlimited_plan_cannot_buy_another(): void
    {
        $member = $this->member();
        $current = $this->hold($member, Plan::factory()->create());

        $this->buy($member, 'ar')
            ->assertStatus(422)
            ->assertJsonPath('message', 'لديك باقة سارية حتى '.$current->current_period_end->timezone('Asia/Damascus')->format('d/m/Y'));

        $this->assertSame(5000, $this->balance($member));
        $this->assertSame(1, Membership::query()->where('owner_type', OwnerType::User)->where('owner_id', $member->id)->count());
    }

    public function test_a_member_whose_limited_hours_are_spent_can_buy(): void
    {
        $member = $this->member();
        $this->spend($this->hold($member, Plan::factory()->coworking(10)->create()), $member, 36000);

        $this->buy($member)->assertCreated();
    }

    public function test_spent_hours_this_month_are_enough_on_a_monthly_plan(): void
    {
        $member = $this->member();
        $plan = Plan::factory()->coworking(10)->create(['allowance_cycle' => AllowanceCycle::Monthly]);
        $current = $this->hold($member, $plan, now()->subDays(40), 90);
        $this->spend($current, $member, 36000);

        $this->buy($member)->assertCreated();
    }

    public function test_hours_left_still_blocks(): void
    {
        $member = $this->member();
        $this->spend($this->hold($member, Plan::factory()->coworking(10)->create()), $member, 35000);

        $this->buy($member)->assertStatus(422);
    }

    public function test_a_company_plan_does_not_block_a_personal_purchase(): void
    {
        $member = $this->member();
        $company = Company::factory()->create();
        $company->members()->attach($member->id, ['is_admin' => false]);
        Membership::factory()->create([
            'plan_id' => Plan::factory()->create()->id,
            'owner_type' => OwnerType::Company,
            'owner_id' => $company->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now()->subDays(3),
            'current_period_end' => now()->addDays(27),
        ]);

        $this->buy($member)->assertCreated();
    }

    public function test_reception_and_admin_refuse_the_same_way(): void
    {
        $blocked = $this->member();
        $this->hold($blocked, Plan::factory()->create());
        $free = $this->member();
        $this->spend($this->hold($free, Plan::factory()->coworking(10)->create()), $free, 36000);
        $other = $this->member();
        $this->spend($this->hold($other, Plan::factory()->coworking(10)->create()), $other, 36000);

        $staff = User::factory()->create();
        $staff->assignRole('admin');
        Sanctum::actingAs($staff, ['*']);
        $message = 'You already have an active plan until';

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/plan-purchases', ['plan_id' => $this->next->id, 'user_id' => $blocked->id, 'payment_method' => 'cash'])
            ->assertStatus(422)->assertSee($message);
        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/memberships', ['plan_id' => $this->next->id, 'user_id' => $blocked->id])
            ->assertStatus(422)->assertSee($message);

        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/plan-purchases', ['plan_id' => $this->next->id, 'user_id' => $free->id, 'payment_method' => 'cash'])
            ->assertCreated();
        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/memberships', ['plan_id' => $this->next->id, 'user_id' => $other->id])
            ->assertCreated();
    }

    public function test_the_catalog_says_whether_the_member_can_subscribe(): void
    {
        $member = $this->member();
        $current = $this->hold($member, Plan::factory()->coworking(10)->create());
        Sanctum::actingAs($member, ['*']);

        $blocked = collect($this->withHeader('lang', 'en')->getJson('/api/v1/member/packages')->assertOk()->json('data'))->firstWhere('id', $this->next->id);
        $this->assertFalse($blocked['can_subscribe']);
        $this->assertStringStartsWith('You already have an active plan until', $blocked['subscribe_blocked_reason']);

        $this->spend($current, $member, 36000);

        $open = collect($this->withHeader('lang', 'en')->getJson('/api/v1/member/packages')->assertOk()->json('data'))->firstWhere('id', $this->next->id);
        $this->assertTrue($open['can_subscribe']);
        $this->assertNull($open['subscribe_blocked_reason']);
    }

    public function test_buying_after_spent_hours_ends_the_old_plan(): void
    {
        $member = $this->member();
        $old = $this->hold($member, Plan::factory()->coworking(10)->create());
        $this->spend($old, $member, 36000);

        $new = Membership::query()->findOrFail($this->buy($member)->assertCreated()->json('data.id'));

        $this->assertFalse($old->fresh()->isLive());
        $this->assertFalse((bool) $old->fresh()->auto_renew);
        $this->assertSame($new->id, app(MembershipResolver::class)->activeFor($member)->id);
        $this->getJson('/api/v1/member/memberships/current')->assertJsonPath('data.membership_id', $new->id);
    }

    public function test_the_old_plans_cafe_balance_is_gone(): void
    {
        $member = $this->member();
        $old = $this->hold($member, Plan::factory()->coworking(10)->create());
        $this->spend($old, $member, 36000);
        $wallet = Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail();
        app(WalletService::class)->creditCategorized($wallet, WalletTransactionCategory::Cafe, '200', WalletTransactionSource::SubscriptionGrant, $old->current_period_end, null, [], 'Cafe');
        $this->next->balances()->create(['category' => WalletTransactionCategory::Cafe, 'amount_points' => 50]);

        $this->buy($member)->assertCreated();

        $this->assertSame(50, app(WalletService::class)->categoryBalance($wallet->fresh(), $member, WalletTransactionCategory::Cafe));
    }

    public function test_a_card_purchase_ends_the_old_plan_when_it_is_fulfilled(): void
    {
        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => '99990001',
            'paymera.currency' => 'USD',
            'paymera.default_lang' => 'en',
        ]);
        Http::fake(['*/api/create-payment' => Http::response([
            'ErrorMessage' => 'Success',
            'ErrorCode' => 0,
            'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-1/en', 'paymentId' => 'pay-1'],
        ])]);
        $member = $this->member();
        $old = $this->hold($member, Plan::factory()->coworking(10)->create());
        $this->spend($old, $member, 36000);

        Sanctum::actingAs($member, ['*']);
        $this->withHeader('lang', 'en')->postJson('/api/v1/member/memberships', ['plan_id' => $this->next->id, 'payment_method' => 'paymera'])
            ->assertCreated();

        // The hours come back before the card is accepted: the paid plan
        // still replaces the old one rather than running beside it.
        PlanHourUsage::query()->where('membership_id', $old->id)->delete();
        $payment = Payment::sole();
        $payment->forceFill(['status' => PaymentStatus::Accepted])->save();
        app(PaymentService::class)->fulfilAcceptedGatewayPayment($payment);

        $this->assertSame(1, Membership::query()->where('owner_type', OwnerType::User)->where('owner_id', $member->id)->live()->count());
        $this->assertFalse($old->fresh()->isLive());
    }

    public function test_the_old_plans_seat_is_free_again(): void
    {
        $this->seats->update(['capacity' => 1]);
        $branchId = $this->seats->building->branch_id;
        $member = $this->member();
        $old = $this->hold($member, Plan::factory()->coworking(10)->create());
        $old->forceFill(['branch_id' => $branchId])->save();
        $this->spend($old, $member, 36000);

        $new = Membership::query()->findOrFail($this->buy($member)->assertCreated()->json('data.id'));

        $this->assertSame($branchId, $new->branch_id);
        $this->assertSame(1, Membership::query()->where('branch_id', $branchId)->live()->count());
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 5000, WalletTransactionSource::TopUp);

        return $member;
    }

    private function hold(User $member, Plan $plan, ?Carbon $start = null, int $days = 30): Membership
    {
        $start ??= now()->subDays(5);

        return Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addDays($days),
        ]);
    }

    private function spend(Membership $membership, User $member, int $seconds): void
    {
        PlanHourUsage::factory()->create([
            'membership_id' => $membership->id,
            'user_id' => $member->id,
            'allowance_kind' => 'coworking',
            'cycle_start' => app(PlanAllowanceService::class)->cycleFor($membership, now())[0],
            'seconds' => $seconds,
        ]);
    }

    private function buy(User $member, string $lang = 'en')
    {
        Sanctum::actingAs($member, ['*']);

        return $this->withHeader('lang', $lang)->postJson('/api/v1/member/memberships', ['plan_id' => $this->next->id]);
    }

    private function balance(User $member): int
    {
        return app(WalletService::class)->generalBalance(Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail());
    }
}
