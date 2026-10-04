<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
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
 * plan_discounts turns a plan_features discount sentence into a real,
 * enforced discount on the booking pricing path
 * (docs/decisions/plan-discounts-design.md).
 */
class PlanDiscountBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Needed for the HTTP-driven tests below (batch/preview/detail),
        // which act as an authenticated member through role-gated routes.
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function roomSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge(['hourly_rate_points' => 100], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    private function businessSpace(array $attributes = []): Space
    {
        $space = Space::factory()->business()->create(array_merge(['hourly_rate_points' => 100], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function slot(int $hour, int $durationMinutes = 60): array
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return [$start, $start->copy()->addMinutes($durationMinutes)];
    }

    private function memberWithFundedWallet(int $points = 500): array
    {
        $member = User::factory()->create();
        $wallet = Wallet::factory()->create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        (new WalletService)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);

        return [$member, $wallet];
    }

    /**
     * Same as memberWithFundedWallet(), plus the `member` role and a
     * Sanctum session — for the HTTP-driven tests below, which hit
     * role-gated routes rather than calling a service directly.
     *
     * @return array{0: User, 1: Wallet}
     */
    private function actingAsFundedMember(int $points = 500): array
    {
        [$member, $wallet] = $this->memberWithFundedWallet($points);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return [$member, $wallet];
    }

    private function activeMembership(User $member, Plan $plan): Membership
    {
        return Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
            'status' => MembershipStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(30),
        ]);
    }

    public function test_a_membership_discount_on_a_specific_space_reduces_the_wallet_debit(): void
    {
        $space = $this->roomSpace();
        [$member, $wallet] = $this->memberWithFundedWallet();
        $plan = Plan::factory()->create();
        $discount = $plan->discounts()->create([
            'percentage' => 30,
            'space_id' => $space->id,
            'description' => ['en' => '30% on this room', 'ar' => 'خصم 30%'],
        ]);
        $membership = $this->activeMembership($member, $plan);
        [$start, $end] = $this->slot(10);

        $booking = app(BookingCreationService::class)->create($space, $member, $start, $end);

        $this->assertSame(PaymentState::Paid, $booking->payment_state);
        // Raw rate is 100 points/hour; 30% off leaves a 70-point debit.
        $this->assertSame(1, $wallet->transactions()->where('amount', -70)->count());
        $this->assertSame(0, $wallet->transactions()->where('amount', -100)->count());

        $redemption = PlanDiscountRedemption::where('booking_id', $booking->id)->first();
        $this->assertNotNull($redemption);
        $this->assertSame($discount->id, $redemption->plan_discount_id);
        $this->assertSame($membership->id, $redemption->membership_id);
        $this->assertSame(30, $redemption->points_discounted);
    }

    public function test_a_usage_limit_of_one_charges_full_price_on_the_second_booking(): void
    {
        $space = $this->roomSpace();
        [$member, $wallet] = $this->memberWithFundedWallet();
        $plan = Plan::factory()->create();
        $plan->discounts()->create([
            'percentage' => 30,
            'space_id' => $space->id,
            'usage_limit' => 1,
        ]);
        $this->activeMembership($member, $plan);

        [$firstStart, $firstEnd] = $this->slot(10);
        $first = app(BookingCreationService::class)->create($space, $member, $firstStart, $firstEnd);

        [$secondStart, $secondEnd] = $this->slot(14);
        $second = app(BookingCreationService::class)->create($space, $member, $secondStart, $secondEnd);

        $this->assertSame(1, $wallet->transactions()->where('amount', -70)->count());
        $this->assertSame(1, $wallet->transactions()->where('amount', -100)->count());
        $this->assertSame(1, PlanDiscountRedemption::count());
        $this->assertSame($first->id, PlanDiscountRedemption::sole()->booking_id);
        $this->assertNotSame($second->id, PlanDiscountRedemption::sole()->booking_id);
    }

    public function test_a_space_type_scoped_discount_applies_to_any_room_but_not_a_business_space(): void
    {
        $roomSpace = $this->roomSpace();
        $businessSpace = $this->businessSpace();
        [$member, $wallet] = $this->memberWithFundedWallet(1000);
        $plan = Plan::factory()->create();
        $plan->discounts()->create([
            'percentage' => 30,
            'space_type' => SpaceType::Room->value,
        ]);
        $this->activeMembership($member, $plan);

        [$roomStart, $roomEnd] = $this->slot(10);
        app(BookingCreationService::class)->create($roomSpace, $member, $roomStart, $roomEnd);

        [$businessStart, $businessEnd] = $this->slot(14);
        app(BookingCreationService::class)->create($businessSpace, $member, $businessStart, $businessEnd);

        // The room booking got the space_type discount (100 -> 70); the
        // business-typed space never matched it and was charged in full.
        $this->assertSame(1, $wallet->transactions()->where('amount', -70)->count());
        $this->assertSame(1, $wallet->transactions()->where('amount', -100)->count());
        $this->assertSame(1, PlanDiscountRedemption::count());
    }

    public function test_cancelling_a_discounted_booking_refunds_only_the_discounted_amount_and_voids_the_redemption(): void
    {
        $space = $this->roomSpace();
        [$member, $wallet] = $this->memberWithFundedWallet();
        $plan = Plan::factory()->create();
        $plan->discounts()->create([
            'percentage' => 30,
            'space_id' => $space->id,
        ]);
        $this->activeMembership($member, $plan);
        [$start, $end] = $this->slot(10);

        $booking = app(BookingCreationService::class)->create($space, $member, $start, $end);
        $this->assertNotNull(PlanDiscountRedemption::where('booking_id', $booking->id)->first());

        app(BookingCancellationService::class)->cancel($booking);

        $refund = $wallet->transactions()
            ->where('source', WalletTransactionSource::Refund)
            ->first();

        $this->assertNotNull($refund);
        // Only the 70 points actually charged come back — not the raw
        // 100-point rate.
        $this->assertSame(70, (int) $refund->amount);
        $this->assertNull(PlanDiscountRedemption::where('booking_id', $booking->id)->first());
    }

    public function test_extending_a_discounted_booking_charges_the_discounted_difference(): void
    {
        $space = $this->roomSpace(['slot_granularity_minutes' => 30]);
        [$member, $wallet] = $this->memberWithFundedWallet();
        $plan = Plan::factory()->create();
        $discount = $plan->discounts()->create([
            'percentage' => 30,
            'space_id' => $space->id,
        ]);
        $membership = $this->activeMembership($member, $plan);

        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-08-17 11:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'checked_in_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->setTimezone('UTC'),
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        // The original 1-hour booking was charged 70 (100 raw, 30% off).
        $redemption = PlanDiscountRedemption::create([
            'plan_discount_id' => $discount->id,
            'membership_id' => $membership->id,
            'booking_id' => $booking->id,
            'points_discounted' => 30,
        ]);

        // A booking extends only while it runs
        // (docs/decisions/booking-outcomes-and-extension.md).
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus'));
        app(BookingExtensionService::class)->extend($booking, 60);

        // New duration is 2 hours: raw 200, 30% off leaves 140. The
        // already-discounted 70 was charged, so only the 70-point
        // difference is debited — not the raw 100-point difference.
        $this->assertSame(1, $wallet->transactions()->where('amount', -70)->count());
        $this->assertSame(0, $wallet->transactions()->where('amount', -100)->count());
        $this->assertSame(PaymentState::Paid, $booking->fresh()->payment_state);
        $this->assertSame(60, $redemption->fresh()->points_discounted);
    }

    /**
     * Neither window in a batch has a PlanDiscountRedemption row yet when
     * the second is evaluated — nothing is persisted until the whole batch
     * is accepted — so the in-memory $consumedInBatch tracking is what
     * stops a usage_limit of 1 from being claimed twice in one request.
     */
    public function test_a_usage_limited_discount_is_claimed_by_only_the_first_window_of_a_batch(): void
    {
        $space = $this->roomSpace();
        [$member, $wallet] = $this->actingAsFundedMember();
        $plan = Plan::factory()->create();
        $plan->discounts()->create([
            'percentage' => 30,
            'space_id' => $space->id,
            'usage_limit' => 1,
        ]);
        $this->activeMembership($member, $plan);

        [$firstStart, $firstEnd] = $this->slot(10);
        [$secondStart, $secondEnd] = $this->slot(14);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/batch', [
            'payment_method' => 'wallet',
            'windows' => [
                [
                    'space_id' => $space->id,
                    'start_at' => $firstStart->format('Y-m-d H:i'),
                    'end_at' => $firstEnd->format('Y-m-d H:i'),
                ],
                [
                    'space_id' => $space->id,
                    'start_at' => $secondStart->format('Y-m-d H:i'),
                    'end_at' => $secondEnd->format('Y-m-d H:i'),
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame(2, Booking::count());

        // One debit per booking: 70 (discounted) + 100 (full price).
        $this->assertSame(1, $wallet->transactions()->where('amount', -70)->count());
        $this->assertSame(1, $wallet->transactions()->where('amount', -100)->count());

        $bookings = Booking::orderBy('start_at')->get();
        $first = $bookings->first();
        $second = $bookings->last();

        $this->assertSame(1, PlanDiscountRedemption::count());
        $redemption = PlanDiscountRedemption::sole();
        $this->assertSame($first->id, $redemption->booking_id);
        $this->assertSame(30, $redemption->points_discounted);
        $this->assertNull(PlanDiscountRedemption::where('booking_id', $second->id)->first());
    }

    public function test_the_preview_endpoint_shows_the_discounted_total_for_an_eligible_member(): void
    {
        $space = $this->roomSpace();
        [$member] = $this->actingAsFundedMember();
        $plan = Plan::factory()->create();
        $plan->discounts()->create([
            'percentage' => 30,
            'space_id' => $space->id,
        ]);
        $this->activeMembership($member, $plan);
        [$start, $end] = $this->slot(10);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $space->id,
            'start_at' => $start->format('Y-m-d H:i'),
            'end_at' => $end->format('Y-m-d H:i'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.breakdown.total.points', 70);
        $response->assertJsonPath('data.breakdown.subtotal.points', 70);
        $this->assertNotNull(
            collect($response->json('data.breakdown.lines'))->firstWhere('key', 'discount'),
            'Expected a discount line in the breakdown.'
        );
    }

    public function test_the_preview_endpoint_shows_the_raw_total_when_the_member_has_no_eligible_discount(): void
    {
        $space = $this->roomSpace();
        $this->actingAsFundedMember();
        [$start, $end] = $this->slot(10);

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $space->id,
            'start_at' => $start->format('Y-m-d H:i'),
            'end_at' => $end->format('Y-m-d H:i'),
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.breakdown.total.points', 100);
        $this->assertNull(
            collect($response->json('data.breakdown.lines'))->firstWhere('key', 'discount'),
            'Did not expect a discount line in the breakdown.'
        );
    }

    /**
     * The detail view reads the discount an already-created booking
     * actually got from its ledger row, never re-resolving it — so it
     * keeps showing the same discount even after the PlanDiscount that
     * granted it is deactivated.
     */
    public function test_an_existing_bookings_detail_keeps_showing_its_original_discount_after_the_discount_is_deactivated(): void
    {
        $space = $this->roomSpace();
        [$member] = $this->actingAsFundedMember();
        $plan = Plan::factory()->create();
        $discount = $plan->discounts()->create([
            'percentage' => 30,
            'space_id' => $space->id,
        ]);
        $this->activeMembership($member, $plan);
        [$start, $end] = $this->slot(10);

        $booking = app(BookingCreationService::class)->create($space, $member, $start, $end);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}");
        $response->assertOk();
        $response->assertJsonPath('data.total.points', 70);

        // The discount becomes ineligible for any future booking...
        $discount->update(['is_active' => false]);

        // ...but this booking's own detail is unaffected, since it reads
        // the historical redemption row rather than re-resolving. (The detail
        // no longer itemises lines — only its total — since 2026-10-04.)
        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}");
        $response->assertOk();
        $response->assertJsonPath('data.total.points', 70);
    }
}
