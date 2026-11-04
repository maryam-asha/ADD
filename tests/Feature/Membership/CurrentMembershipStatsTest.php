<?php

namespace Tests\Feature\Membership;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Models\PlanHourUsage;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\CurrentMembershipStats;
use App\Domain\Membership\Services\ResourceQuotaService;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A member's plan as one list of cards the app draws as they come: a ring
 * when there is a total, a plain value when there is none.
 */
class CurrentMembershipStatsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingSeeder::class);
        $this->seed(CurrencySeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->member = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_period_card_counts_days(): void
    {
        app()->setLocale('ar');

        $period = $this->card($this->subscribe(Plan::factory()->create()), 'period');

        $this->assertSame('ring', $period['display']);
        $this->assertSame('period', $period['type']);
        $this->assertSame('days', $period['unit']);
        $this->assertSame(30, $period['total']);
        $this->assertSame(12, $period['used']);
        $this->assertSame(18, $period['remaining']);
        $this->assertSame(40, $period['percent']);
        $this->assertSame('متبقٍ 18 يوماً', $period['label']);
    }

    public function test_the_period_card_counts_calendar_days_regardless_of_time(): void
    {
        $start = Carbon::parse('2026-11-01 20:00:00', 'Asia/Damascus')->utc();
        $membership = Membership::factory()->create([
            'plan_id' => Plan::factory()->create()->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $this->member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addDays(30),
        ]);

        // Viewed on the 5th of the month at 08:00 AM
        Carbon::setTestNow(Carbon::parse('2026-11-05 08:00:00', 'Asia/Damascus')->utc());
        $cardMorning = $this->card($membership, 'period');
        $this->assertSame(30, $cardMorning['total']);
        $this->assertSame(4, $cardMorning['used']);
        $this->assertSame(26, $cardMorning['remaining']);

        // Viewed on the 5th of the month at 23:00 PM
        Carbon::setTestNow(Carbon::parse('2026-11-05 23:00:00', 'Asia/Damascus')->utc());
        $cardEvening = $this->card($membership, 'period');
        $this->assertSame(4, $cardEvening['used']);
        $this->assertSame(26, $cardEvening['remaining']);
    }

    public function test_unlimited_hours_are_a_value(): void
    {
        $membership = $this->subscribe(Plan::factory()->create());
        $this->spend($membership, 5400);

        $hours = $this->card($membership, 'workspace_hours');

        $this->assertSame('value', $hours['display']);
        $this->assertNull($hours['total']);
        $this->assertSame(1.5, $hours['used']);
        $this->assertNull($hours['remaining']);
        $this->assertNull($hours['percent']);
    }

    public function test_limited_hours_are_a_ring(): void
    {
        $membership = $this->subscribe(Plan::factory()->coworking(20)->create());
        $this->spend($membership, 18000);

        $hours = $this->card($membership, 'workspace_hours');

        $this->assertSame('ring', $hours['display']);
        $this->assertEquals(20, $hours['total']);
        $this->assertEquals(5, $hours['used']);
        $this->assertEquals(15, $hours['remaining']);
        $this->assertSame(25, $hours['percent']);
    }

    public function test_a_discount_on_the_event_halls_counts_its_uses(): void
    {
        app()->setLocale('ar');
        $plan = Plan::factory()->create();
        $discount = $plan->discounts()->create(['percentage' => 30, 'space_type' => SpaceType::EventHall, 'usage_limit' => 6]);
        $membership = $this->subscribe($plan);
        $this->redeem($discount->id, $membership, 2);

        $card = $this->card($membership, "discount:{$discount->id}");

        $this->assertSame('discount', $card['type']);
        $this->assertSame('ring', $card['display']);
        $this->assertSame(30, $card['percent_off']);
        $this->assertSame(6, $card['total']);
        $this->assertSame(2, $card['used']);
        $this->assertSame(4, $card['remaining']);
        $this->assertSame('حسم 30% على قاعة الفعاليات', $card['title']);
    }

    public function test_an_unlimited_discount_is_a_value(): void
    {
        $plan = Plan::factory()->create();
        $discount = $plan->discounts()->create(['percentage' => 10, 'space_type' => SpaceType::Room]);
        $membership = $this->subscribe($plan);
        $this->redeem($discount->id, $membership, 3);

        $card = $this->card($membership, "discount:{$discount->id}");

        $this->assertSame('value', $card['display']);
        $this->assertNull($card['total']);
        $this->assertSame(3, $card['used']);
    }

    public function test_a_plan_without_workspace_has_no_hours_card(): void
    {
        $keys = array_column(app(CurrentMembershipStats::class)->for($this->subscribe(Plan::factory()->notCoworking()->create()), $this->member), 'key');

        $this->assertSame(['period'], $keys);
    }

    public function test_a_cafe_balance_is_a_ring_in_usd(): void
    {
        $plan = Plan::factory()->create();
        $plan->balances()->create(['category' => WalletTransactionCategory::Cafe, 'amount_points' => 2000]);
        $membership = $this->subscribe($plan);
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditCategorized($wallet, WalletTransactionCategory::Cafe, '2000', WalletTransactionSource::SubscriptionGrant, $membership->current_period_end, null, [], 'Cafe');
        WalletTransaction::create(['wallet_id' => $wallet->id, 'amount' => -500, 'category' => WalletTransactionCategory::Cafe, 'source' => WalletTransactionSource::TopUp, 'description' => 'Coffee']);

        $card = $this->card($membership, 'balance:cafe');
        $points = app(PointConversionService::class);

        $this->assertSame('ring', $card['display']);
        $this->assertSame('usd', $card['unit']);
        $this->assertEquals((float) $points->pointsToUsd(2000), $card['total']);
        $this->assertEquals((float) $points->pointsToUsd(500), $card['used']);
        $this->assertSame(25, $card['percent']);
    }

    public function test_a_resource_grant_is_a_ring(): void
    {
        $plan = Plan::factory()->create();
        $type = ResourceType::create(['slug' => 'printing', 'name' => ['en' => 'Printing', 'ar' => 'طباعة'], 'unit_label' => ['en' => 'page', 'ar' => 'صفحة']]);
        $grant = $plan->resourceGrants()->create(['resource_type_id' => $type->id, 'quantity' => 10]);
        $membership = $this->subscribe($plan);
        app(ResourceQuotaService::class)->consume($grant, $membership, 3, $this->member);

        $card = $this->card($membership, "resource:{$grant->id}");

        $this->assertSame('ring', $card['display']);
        $this->assertEquals(10, $card['total']);
        $this->assertEquals(3, $card['used']);
        $this->assertEquals(7, $card['remaining']);
    }

    private function subscribe(Plan $plan): Membership
    {
        $start = now()->subDays(12);

        return Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $this->member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => $start,
            'current_period_end' => $start->copy()->addDays(30),
        ]);
    }

    private function spend(Membership $membership, int $seconds): void
    {
        PlanHourUsage::factory()->create([
            'membership_id' => $membership->id,
            'user_id' => $this->member->id,
            'allowance_kind' => 'coworking',
            'cycle_start' => $membership->current_period_start,
            'seconds' => $seconds,
        ]);
    }

    private function redeem(int $discountId, Membership $membership, int $times): void
    {
        foreach (range(1, $times) as $_) {
            PlanDiscountRedemption::create([
                'plan_discount_id' => $discountId,
                'membership_id' => $membership->id,
                'user_id' => $this->member->id,
                'counted_at' => now()->subDay(),
                'points_discounted' => 10,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function card(Membership $membership, string $key): array
    {
        $cards = collect(app(CurrentMembershipStats::class)->for($membership->fresh(), $this->member));
        $card = $cards->firstWhere('key', $key);

        $this->assertNotNull($card, "No `{$key}` card in: ".$cards->pluck('key')->implode(', '));
        $this->assertSame(
            ['key', 'type', 'display', 'title', 'subtitle', 'icon', 'unit', 'total', 'used', 'remaining', 'percent', 'label'],
            array_values(array_diff(array_keys($card), ['percent_off'])),
        );

        return $card;
    }
}
