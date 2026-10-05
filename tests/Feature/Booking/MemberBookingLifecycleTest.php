<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The member's own view of their bookings: the list, one booking's
 * detail and stepper, self-service cancellation, and the confirmation
 * screen.
 */
class MemberBookingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'name' => ['en' => 'Meeting Room A', 'ar' => 'غرفة اجتماعات أ'],
            'hourly_rate_points' => 120,
            'slot_granularity_minutes' => 60,
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bookingFor(User $member, Space $space, int $hour, array $attributes = []): Booking
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return Booking::factory()->create(array_merge([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addHour()->setTimezone('UTC'),
        ], $attributes));
    }

    // ---------------------------------------------------------------
    // List
    // ---------------------------------------------------------------

    public function test_a_member_sees_only_their_own_bookings(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();

        $this->bookingFor($member, $space, 14);
        $this->bookingFor(User::factory()->create(), $space, 16);

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings');

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, $response->json('meta.total'));
    }

    /**
     * The `filter` tabs are gone (user decision, 2026-10-03); the list says
     * which bookings are still to come with `is_upcoming`, and puts them first.
     */
    public function test_the_list_marks_and_leads_with_the_bookings_still_to_come(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();

        // now() is 08:00, so 06:00-07:00 is over and 14:00-15:00 still to come.
        $earlier = $this->bookingFor($member, $space, 6);
        $later = $this->bookingFor($member, $space, 14);

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings')->assertOk();

        $this->assertSame([$later->id, $earlier->id], array_column($response->json('data'), 'id'));
        $this->assertSame([true, false], array_column($response->json('data'), 'is_upcoming'));
    }

    public function test_the_list_does_not_run_a_query_per_card(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();

        foreach ([10, 12, 14, 16, 18] as $hour) {
            $this->bookingFor($member, $space, $hour);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings')->assertOk();

        // The page, its total and the space eager load — bounded, and
        // crucially not growing with the number of bookings.
        $this->assertLessThanOrEqual(15, $queries, "The list issued {$queries} queries for five bookings.");
    }

    public function test_the_list_is_closed_to_an_unauthenticated_caller(): void
    {
        $this->getJson('/api/v1/member/bookings')->assertUnauthorized();
    }

    // ---------------------------------------------------------------
    // Detail and stepper
    // ---------------------------------------------------------------

    public function test_a_pending_booking_shows_the_review_step_as_current(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace(['requires_approval' => true]);
        $booking = $this->bookingFor($member, $space, 14, ['status' => BookingStatus::Pending]);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Under Review')
            ->assertJsonPath('data.request_status.current', 'under_review')
            ->assertJsonPath('data.request_status.steps.0.state', 'complete')
            ->assertJsonPath('data.request_status.steps.1.state', 'current')
            ->assertJsonPath('data.request_status.steps.2.state', 'upcoming');
    }

    public function test_a_rejected_booking_shows_its_reason_on_a_completed_decision_step(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace(['requires_approval' => true]);
        $booking = $this->bookingFor($member, $space, 14, [
            'status' => BookingStatus::Rejected,
            'rejection_reason' => 'Room reserved for maintenance',
            'approved_at' => now(),
        ]);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonPath('data.request_status.steps.2.key', 'rejected')
            ->assertJsonPath('data.request_status.steps.2.state', 'complete')
            ->assertJsonPath('data.request_status.rejection_reason', 'Room reserved for maintenance');
    }

    /**
     * A space that auto-confirms never had a review, so the stepper must
     * not invent one.
     */
    public function test_an_auto_confirmed_booking_collapses_the_stepper_to_two_steps(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $booking = $this->bookingFor($member, $space, 14, [
            'status' => BookingStatus::Confirmed,
            'approved_at' => null,
        ]);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data.request_status.steps')
            ->assertJsonPath('data.request_status.current', 'confirmed');
    }

    /**
     * Once an approved booking has played out, its outcome follows the
     * approval as a fourth step.
     */
    public function test_a_completed_booking_adds_its_outcome_after_the_approval(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $booking = $this->bookingFor($member, $space, 14, [
            'status' => BookingStatus::Completed,
            'approved_at' => now(),
        ]);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonCount(4, 'data.request_status.steps')
            ->assertJsonPath('data.request_status.current', 'outcome')
            ->assertJsonPath('data.request_status.steps.2.key', 'approved')
            ->assertJsonPath('data.request_status.steps.3.key', 'completed')
            ->assertJsonPath('data.request_status.steps.3.label', 'Completed')
            ->assertJsonPath('data.request_status.steps.3.state', 'complete');
    }

    /** An auto-confirmed no-show: its outcome follows the confirmation. */
    public function test_an_auto_confirmed_no_show_adds_its_outcome_after_the_confirmation(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $booking = $this->bookingFor($member, $space, 14, [
            'status' => BookingStatus::NoShow,
            'approved_at' => null,
        ]);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonCount(3, 'data.request_status.steps')
            ->assertJsonPath('data.request_status.current', 'outcome')
            ->assertJsonPath('data.request_status.steps.1.key', 'confirmed')
            ->assertJsonPath('data.request_status.steps.2.key', 'no_show')
            ->assertJsonPath('data.request_status.steps.2.label', 'No-show');
    }

    /**
     * The total and the window, as the screens draw them; the itemised
     * breakdown and the note left the detail on 2026-10-04
     * (docs/decisions/booking-outcomes-and-extension.md). A booking without
     * its own `price_points` is priced from its window.
     */
    public function test_the_detail_carries_the_total_and_the_window(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $booking = $this->bookingFor($member, $space, 14);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}");

        $response->assertOk()
            ->assertJsonPath('data.total.points', 120)
            ->assertJsonPath('data.time_label', '02:00 PM - 03:00 PM (1h)')
            ->assertJsonPath('data.space.title', 'Meeting Room A');
    }

    public function test_a_member_cannot_read_another_members_booking(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $booking = $this->bookingFor(User::factory()->create(), $space, 14);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertForbidden();
    }

    // ---------------------------------------------------------------
    // Cancellation
    // ---------------------------------------------------------------

    public function test_a_member_can_cancel_their_own_upcoming_booking(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $booking = $this->bookingFor($member, $space, 14);

        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/member/bookings/{$booking->id}/cancel")
            ->assertOk()
            ->assertExactJson(['message' => 'Booking cancelled.']);

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
    }

    public function test_a_member_cannot_cancel_another_members_booking(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $booking = $this->bookingFor(User::factory()->create(), $space, 14);

        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/member/bookings/{$booking->id}/cancel")
            ->assertForbidden();

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_cancelling_inside_the_window_is_refused(): void
    {
        $member = $this->actingAsMember();
        // 60-minute window, booking at 08:30, now is 08:00 — inside it.
        $space = $this->openSpace(['cancellation_window_minutes' => 60]);
        $start = Carbon::parse('2026-08-17 08:30:00', 'Asia/Damascus');
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addHour()->setTimezone('UTC'),
        ]);

        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/member/bookings/{$booking->id}/cancel")
            ->assertStatus(422);

        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }

    /**
     * The button and the endpoint must agree. If can_cancel is ever true
     * where cancel() would refuse, the app shows a button that 422s.
     */
    public function test_can_cancel_is_true_exactly_when_the_cancel_endpoint_would_succeed(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace(['cancellation_window_minutes' => 60]);

        $cancellable = $this->bookingFor($member, $space, 14);
        $tooLate = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => Carbon::parse('2026-08-17 08:30:00', 'Asia/Damascus')->setTimezone('UTC'),
            'end_at' => Carbon::parse('2026-08-17 09:30:00', 'Asia/Damascus')->setTimezone('UTC'),
        ]);

        foreach ([[$cancellable, true, 200], [$tooLate, false, 422]] as [$booking, $expectedFlag, $expectedStatus]) {
            $flag = $this->withHeader('lang', 'en')
                ->getJson("/api/v1/member/bookings/{$booking->id}")
                ->json('data.actions.can_cancel');

            $this->assertSame($expectedFlag, $flag);

            $this->withHeader('lang', 'en')
                ->postJson("/api/v1/member/bookings/{$booking->id}/cancel")
                ->assertStatus($expectedStatus);
        }
    }

    public function test_cancelling_a_wallet_paid_booking_refunds_the_points(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 500, WalletTransactionSource::TopUp, 'Seed');

        $booking = $this->bookingFor($member, $space, 14, [
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
        ]);

        $before = app(WalletService::class)->generalBalance($wallet);

        $this->withHeader('lang', 'en')
            ->postJson("/api/v1/member/bookings/{$booking->id}/cancel")
            ->assertOk();

        $this->assertSame($before + 120, app(WalletService::class)->generalBalance($wallet->fresh()));
    }

    // ---------------------------------------------------------------
    // Preview
    // ---------------------------------------------------------------

    public function test_the_preview_returns_a_breakdown_with_zero_vat_and_creates_nothing(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 16:00',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.breakdown.subtotal.points', 240)
            ->assertJsonPath('data.breakdown.vat.rate_percent', 0)
            ->assertJsonPath('data.breakdown.total.points', 240)
            ->assertJsonPath('data.breakdown.total.formatted', '$24.00')
            ->assertJsonPath('data.when.duration_label', '2h')
            ->assertJsonPath('data.is_available', true)
            ->assertJsonPath('data.note_field.max_length', 500);

        $this->assertSame(0, Booking::count(), 'A preview must not create a booking.');
    }

    /**
     * The preview asks the pool the way the POST does: a seat every
     * subscription holds is not free, but the subscriber's own is theirs.
     */
    public function test_the_preview_counts_a_co_space_seat_on_the_members_subscription(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace(['space_type' => SpaceType::CoSpace, 'capacity' => 1, 'is_lockable' => false]);
        $held = Membership::factory()->create(['branch_id' => $space->building->branch_id]);
        $preview = fn () => $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 16:00',
        ])->assertOk();

        $preview()->assertJsonPath('data.is_available', false);

        $held->update(['owner_id' => $member->id]);

        $preview()->assertJsonPath('data.is_available', true);
    }

    public function test_the_preview_says_a_lent_room_is_part_of_co_working(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        SpaceCoworkingWindow::factory()->create([
            'space_id' => $space->id,
            'starts_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
            'ends_at' => Carbon::parse('2026-08-17 18:00', 'Asia/Damascus')->utc(),
        ]);

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 16:00',
        ])
            ->assertOk()
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.unavailable_reason', __('api.booking.room_in_coworking_mode', [], 'en'));
    }

    public function test_the_preview_lists_a_short_wallet_as_insufficient_rather_than_hiding_it(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 10, WalletTransactionSource::TopUp, 'Seed');

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
        ]);

        $response->assertOk();

        $methods = collect($response->json('data.payment_methods'));
        $walletOption = $methods->firstWhere('key', 'wallet');

        $this->assertNotNull($walletOption, 'A wallet that cannot cover the cost must still be listed.');
        $this->assertFalse($walletOption['sufficient']);

        // Cash becomes the selected fallback when no wallet can pay.
        $this->assertTrue($methods->firstWhere('key', 'cash')['selected']);
    }

    public function test_the_preview_selects_a_wallet_that_can_cover_the_booking(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        app(WalletService::class)->creditGeneral($wallet, 1000, WalletTransactionSource::TopUp, 'Seed');

        $response = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
        ]);

        $response->assertOk();

        $wallet = collect($response->json('data.payment_methods'))->firstWhere('key', 'wallet');

        $this->assertTrue($wallet['sufficient']);
        $this->assertTrue($wallet['selected']);
    }

    /**
     * The preview's refusal and the POST's refusal are the same sentence,
     * for the same reason the calendar chips are.
     */
    public function test_an_unavailable_window_is_previewed_with_the_message_the_post_would_return(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace();
        $this->bookingFor(User::factory()->create(), $space, 14);

        $previewReason = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
        ])->assertOk()->json('data.unavailable_reason');

        $postMessage = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'payment_method' => 'wallet',
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
        ])->assertStatus(422)->json('message');

        $this->assertSame($postMessage, $previewReason);
    }

    public function test_a_booking_stores_the_members_note(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', [
            'payment_method' => 'cash',
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
            'note' => 'Client presentation, need the screen',
        ])->assertCreated();

        $this->assertSame('Client presentation, need the screen', Booking::first()->note);
    }

    public function test_the_arabic_locale_labels_the_status_and_payment_method(): void
    {
        $member = $this->actingAsMember();
        $space = $this->openSpace(['requires_approval' => true]);
        $booking = $this->bookingFor($member, $space, 14, ['status' => BookingStatus::Pending]);

        $this->withHeader('lang', 'ar')
            ->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.status_label', 'قيد المراجعة')
            ->assertJsonPath('data.request_status.steps.0.label', 'تم الإرسال');
    }

    public function test_a_window_beyond_the_advance_horizon_is_previewed_with_the_message_the_post_would_return(): void
    {
        app(SettingService::class)->set('booking.max_days_ahead', 7, SettingValueType::Int);
        $this->actingAsMember();
        $space = $this->openSpace();
        $window = [
            'space_id' => $space->id,
            'start_at' => '2026-08-31 14:00',
            'end_at' => '2026-08-31 15:00',
        ];

        $previewReason = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', $window)
            ->assertOk()->json('data.unavailable_reason');

        $postMessage = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', $window + ['payment_method' => 'wallet'])
            ->assertStatus(422)->json('message');

        $this->assertSame(__('api.booking.too_far_ahead', [], 'en'), $postMessage);
        $this->assertSame($postMessage, $previewReason);
    }

    public function test_a_space_that_is_not_active_is_previewed_with_the_message_the_post_would_return(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['status' => OperationalStatus::Maintenance]);
        $window = [
            'space_id' => $space->id,
            'start_at' => '2026-08-17 14:00',
            'end_at' => '2026-08-17 15:00',
        ];

        $previewReason = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings/preview', $window)
            ->assertOk()->json('data.unavailable_reason');

        $postMessage = $this->withHeader('lang', 'en')->postJson('/api/v1/member/bookings', $window + ['payment_method' => 'wallet'])
            ->assertStatus(422)->json('message');

        $this->assertSame(__('api.booking.space_not_bookable', [], 'en'), $postMessage);
        $this->assertSame($postMessage, $previewReason);
    }
}
