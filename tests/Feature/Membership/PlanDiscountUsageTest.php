<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentService;
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
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A plan discount's `usage_limit` counts every booking it was applied to,
 * however and whenever that booking is paid — and the discounted price is
 * the price, even when it is paid after check-out (final review I1).
 */
class PlanDiscountUsageTest extends TestCase
{
    use RefreshDatabase;

    private Space $space;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());

        $this->space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'slot_granularity_minutes' => 60]);
        BusinessHour::factory()->create([
            'branch_id' => $this->space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $this->member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp);

        $plan = Plan::factory()->create();
        $plan->discounts()->create([
            'percentage' => 30,
            'space_id' => $this->space->id,
            'usage_limit' => 1,
        ]);
        Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $this->member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);

        Sanctum::actingAs($this->member, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function book(int $hour, ?string $method = null): Booking
    {
        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', array_filter([
            'space_id' => $this->space->id,
            'start_at' => sprintf('2026-08-17T%02d:00:00+03:00', $hour),
            'end_at' => sprintf('2026-08-17T%02d:00:00+03:00', $hour + 1),
            'payment_method' => $method,
        ]))->assertCreated();

        return Booking::query()->findOrFail($response->json('data.id'));
    }

    public function test_a_booking_left_unpaid_still_uses_up_the_discount(): void
    {
        $first = $this->book(10, 'cash');
        $second = $this->book(12, 'cash');

        $this->assertSame(70, $first->price_points);
        $this->assertSame(100, $second->price_points);
        $this->assertSame($first->id, PlanDiscountRedemption::sole()->booking_id);
        $this->assertSame(30, PlanDiscountRedemption::sole()->points_discounted);
    }

    /** Probe G: cash bookings each paid later from the wallet. */
    public function test_bookings_paid_after_creation_get_the_discount_only_once(): void
    {
        foreach ([10, 12, 14] as $hour) {
            $booking = $this->book($hour, 'cash');

            $this->postJson('/api/v1/member/bookings', ['booking_id' => $booking->id, 'payment_method' => 'wallet'])
                ->assertOk();
        }

        $this->assertSame([70, 100, 100], Booking::query()->orderBy('start_at')->pluck('price_points')->all());
        $this->assertSame(1, PlanDiscountRedemption::count());
        $this->assertSame(730, app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', OwnerType::User)->where('owner_id', $this->member->id)->firstOrFail()
        ));
    }

    public function test_an_unpaid_first_booking_leaves_a_paid_second_booking_at_full_price(): void
    {
        $this->book(10, 'cash');
        $paid = $this->book(12, 'wallet');

        $this->assertSame(PaymentState::Paid, $paid->payment_state);
        $this->assertSame(100, $paid->price_points);
        $this->assertSame(1, PlanDiscountRedemption::count());
    }

    public function test_a_batch_left_unpaid_uses_the_discount_on_its_first_window_only(): void
    {
        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'cash',
            'windows' => [
                ['space_id' => $this->space->id, 'start_at' => '2026-08-17 10:00', 'end_at' => '2026-08-17 11:00'],
                ['space_id' => $this->space->id, 'start_at' => '2026-08-17 14:00', 'end_at' => '2026-08-17 15:00'],
            ],
        ])->assertCreated();

        $bookings = Booking::query()->orderBy('start_at')->get();
        $this->assertSame([70, 100], $bookings->pluck('price_points')->all());
        $this->assertSame($bookings->first()->id, PlanDiscountRedemption::sole()->booking_id);

        $this->assertSame(100, $this->book(17, 'cash')->price_points);
    }

    public function test_cancelling_an_unpaid_discounted_booking_gives_the_discount_back(): void
    {
        $first = $this->book(12, 'cash');
        app(BookingCancellationService::class)->cancel($first);

        $this->assertSame(0, PlanDiscountRedemption::count());
        $this->assertSame(70, $this->book(14, 'cash')->price_points);
    }

    public function test_a_discounted_booking_paid_after_check_out_costs_the_discounted_price(): void
    {
        $booking = $this->book(10, 'cash');
        $booking->forceFill(['checked_in_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc()])->save();

        Carbon::setTestNow(Carbon::parse('2026-08-17 11:05:00', 'Asia/Damascus')->utc());
        app(SessionClosureService::class)->closeOut($booking, Carbon::parse('2026-08-17 11:00', 'Asia/Damascus'));
        $this->assertSame(70, $booking->refresh()->price_points);

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        $settled = app(PaymentService::class)->settleAtReception(PaymentPurpose::Booking, $booking, PaymentMethod::Cash, null, null, $operator);

        $this->assertSame(70, $settled->pointsCharged);
        $this->assertSame(70, Payment::sole()->amount_points);
        $this->assertSame(PaymentState::Paid, $booking->refresh()->payment_state);
    }
}
