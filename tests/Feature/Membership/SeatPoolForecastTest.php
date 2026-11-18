<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Renewals\Enums\RenewalStopReason;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A plan refused for want of a seat says when the member could have it: the
 * earliest moment a seat stays free for the plan's whole period, as the pool
 * is expected to be — a subscription set to renew itself keeps its seats.
 */
class SeatPoolForecastTest extends TestCase
{
    use RefreshDatabase;

    private Space $coSpace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // 10:00 in Damascus (UTC+3).
        Carbon::setTestNow(Carbon::parse('2026-10-05 07:00:00', 'UTC'));

        Cache::forget('default_branch_id');
        $branch = Branch::factory()->create(['is_default' => true]);
        $this->coSpace = Space::factory()->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
            'capacity' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buy(): TestResponse
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 5000, WalletTransactionSource::TopUp);
        Sanctum::actingAs($member, ['*']);

        $plan = Plan::factory()->create(['price_points' => 1200, 'duration_days' => 30, 'is_active' => true, 'is_public' => true]);

        return $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/memberships', ['plan_id' => $plan->id])
            ->assertStatus(422)
            ->assertJsonMissingPath('first_shortfall_at');
    }

    private function holder(array $attributes = []): Membership
    {
        return Membership::factory()->create([
            'branch_id' => $this->coSpace->building->branch_id,
            'current_period_end' => now()->addDays(20),
            ...$attributes,
        ]);
    }

    public function test_says_when_the_subscription_holding_the_last_seat_ends(): void
    {
        $this->holder();

        $this->buy()
            ->assertJsonPath('available_from', '2026-10-25T07:00:00+00:00')
            ->assertJsonPath('message', __('api.membership.seat_pool_full_until', ['date' => 'October 25, 2026, 10:00 AM'], 'en'));
    }

    public function test_a_subscription_set_to_renew_itself_keeps_its_seat(): void
    {
        $this->holder(['auto_renew' => true]);

        $this->buy()
            ->assertJsonPath('available_from', null)
            ->assertJsonPath('message', __('api.membership.seat_pool_full', [], 'en'));
    }

    public function test_a_renewal_that_stopped_frees_the_seat_when_the_subscription_ends(): void
    {
        $this->holder(['auto_renew' => true, 'renewal_stop_reason' => RenewalStopReason::NoSeats]);

        $this->buy()->assertJsonPath('available_from', '2026-10-25T07:00:00+00:00');
    }

    public function test_a_renewal_already_made_is_counted_once_and_ends_when_it_does(): void
    {
        $held = $this->holder(['auto_renew' => true]);
        $this->holder([
            'plan_id' => $held->plan_id,
            'current_period_start' => $held->current_period_end,
            'current_period_end' => $held->current_period_end->copy()->addDays(30),
            'renewed_from_id' => $held->id,
        ]);

        $this->buy()->assertJsonPath('available_from', '2026-11-24T07:00:00+00:00');
    }

    public function test_a_booking_holding_the_last_seat_for_an_hour_delays_the_plan_by_that_hour(): void
    {
        $this->coSpace->update(['capacity' => 2]);
        $this->holder(['current_period_end' => now()->addDays(60)]);
        Booking::factory()->create([
            'space_id' => $this->coSpace->id,
            'start_at' => Carbon::parse('2026-10-06 07:00:00', 'UTC'),
            'end_at' => Carbon::parse('2026-10-06 08:00:00', 'UTC'),
        ]);

        $this->buy()->assertJsonPath('available_from', '2026-10-06T08:00:00+00:00');
    }
}
