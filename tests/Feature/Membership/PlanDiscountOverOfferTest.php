<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Models\Coupon;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A plan's 30% on the events hall beats a 10% offer for everyone on it.
 */
class PlanDiscountOverOfferTest extends TestCase
{
    use RefreshDatabase;

    private Space $hall;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());

        $this->hall = Space::factory()->eventHall()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $this->hall->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        $offer = Coupon::factory()->automatic()->percentage(10)->create();
        $offer->targets()->create(['purpose' => 'booking', 'space_id' => $this->hall->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function targets(): array
    {
        return [
            'the hall itself' => [['space' => true]],
            'every events hall' => [['space_type' => 'event_hall']],
        ];
    }

    #[DataProvider('targets')]
    public function test_the_plan_discount_prices_the_preview_and_the_booking(array $target): void
    {
        $member = $this->subscriber($target);

        $preview = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $this->hall->id,
            'start_at' => '2026-08-17 10:00',
            'end_at' => '2026-08-17 11:00',
        ])->assertOk();

        $this->assertSame(70, $preview->json('data.breakdown.total.points'), json_encode($preview->json('data.breakdown')));

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'space_id' => $this->hall->id,
            'start_at' => '2026-08-17T10:00:00+03:00',
            'end_at' => '2026-08-17T11:00:00+03:00',
            'payment_method' => 'wallet',
        ])->assertCreated();

        $this->assertSame(70, Booking::query()->findOrFail($response->json('data.id'))->price_points);
        $this->assertSame(1, PlanDiscountRedemption::query()->where('user_id', $member->id)->count());
    }

    /** @param  array<string, mixed>  $target */
    private function subscriber(array $target): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp);

        $plan = Plan::factory()->create();
        $plan->discounts()->create(['percentage' => 30] + (isset($target['space']) ? ['space_id' => $this->hall->id] : $target));
        Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);

        Sanctum::actingAs($member, ['*']);

        return $member;
    }
}
