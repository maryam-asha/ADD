<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whether a booking can be extended and by how much
 * (docs/decisions/booking-outcomes-and-extension.md): only while the member
 * is in the room, inside the booking's own time, on a space that allows it —
 * and only as far as the next booking (and its buffer), the branch's
 * closing, or a co-working loan allows.
 */
class BookingExtensionOptionsTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus')->utc());

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function room(string $closeTime = '20:00', array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 30,
        ], $attributes));

        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => $closeTime,
        ]);

        return $space;
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}", 'Asia/Damascus')->utc();
    }

    /**
     * The member's 10:00–11:00 booking, checked in at 10:00.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function inProgress(Space $space, array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'space_id' => $space->id,
            'user_id' => $this->member->id,
            'start_at' => $this->at('10:00'),
            'end_at' => $this->at('11:00'),
            'checked_in_at' => $this->at('10:00'),
        ], $attributes));
    }

    private function bookedFrom(Space $space, string $from, string $to): Booking
    {
        return Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => $this->at($from),
            'end_at' => $this->at($to),
        ]);
    }

    private function extensionOptions(Booking $booking): TestResponse
    {
        return $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}/extension-options");
    }

    // ---------------------------------------------------------------
    // How far
    // ---------------------------------------------------------------

    public function test_the_options_run_up_to_the_next_booking(): void
    {
        $space = $this->room();
        $booking = $this->inProgress($space);
        $this->bookedFrom($space, '13:00', '14:00');

        $response = $this->extensionOptions($booking);

        $response->assertOk()
            ->assertJsonPath('data.can_extend', true)
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.current_end_at', '2026-08-17T11:00:00+03:00')
            ->assertJsonPath('data.latest_end_at', '2026-08-17T13:00:00+03:00');
        $this->assertSame([60, 90, 120], array_column($response->json('data.options'), 'minutes'));
        $this->assertSame(['12:00 PM', '12:30 PM', '01:00 PM'], array_column($response->json('data.options'), 'end_time'));
        $this->assertSame([100, 150, 200], array_column($response->json('data.options'), 'price_points'));
    }

    public function test_a_booked_next_slot_leaves_nothing_to_extend_into(): void
    {
        $space = $this->room();
        $booking = $this->inProgress($space);
        $this->bookedFrom($space, '11:00', '12:00');

        $this->extensionOptions($booking)->assertOk()
            ->assertJsonPath('data.can_extend', false)
            ->assertJsonPath('data.reason', 'next_slot_booked')
            ->assertJsonPath('data.reason_label', __('api.booking.extension_reasons.next_slot_booked', [], 'en'))
            ->assertJsonPath('data.latest_end_at', '2026-08-17T11:00:00+03:00')
            ->assertJsonPath('data.options', []);
    }

    /** The shortest extension is the minimum booking length (60 minutes); 30 free minutes cannot hold it. */
    public function test_less_free_time_than_the_shortest_extension_is_not_enough(): void
    {
        $space = $this->room();
        $booking = $this->inProgress($space);
        $this->bookedFrom($space, '11:30', '12:30');

        $this->extensionOptions($booking)->assertOk()
            ->assertJsonPath('data.can_extend', false)
            ->assertJsonPath('data.reason', 'next_slot_booked')
            ->assertJsonPath('data.latest_end_at', '2026-08-17T11:30:00+03:00');
    }

    public function test_the_buffer_before_the_next_booking_is_kept_free(): void
    {
        $space = $this->room(attributes: ['buffer_minutes' => 30]);
        $booking = $this->inProgress($space);
        $this->bookedFrom($space, '13:00', '14:00');

        $response = $this->extensionOptions($booking)->assertOk()
            ->assertJsonPath('data.latest_end_at', '2026-08-17T12:30:00+03:00');

        $this->assertSame([60, 90], array_column($response->json('data.options'), 'minutes'));
    }

    public function test_the_options_stop_at_the_branch_closing(): void
    {
        $booking = $this->inProgress($this->room('12:00'));

        $response = $this->extensionOptions($booking)->assertOk()
            ->assertJsonPath('data.latest_end_at', '2026-08-17T12:00:00+03:00');

        $this->assertSame([60], array_column($response->json('data.options'), 'minutes'));
    }

    public function test_a_booking_that_ends_at_closing_cannot_extend(): void
    {
        $booking = $this->inProgress($this->room('11:00'));

        $this->extensionOptions($booking)->assertOk()
            ->assertJsonPath('data.can_extend', false)
            ->assertJsonPath('data.reason', 'branch_closing');
    }

    // ---------------------------------------------------------------
    // Whether at all
    // ---------------------------------------------------------------

    public function test_a_space_that_does_not_allow_extension_offers_none(): void
    {
        $booking = $this->inProgress($this->room(attributes: ['allows_extension' => false]));

        $this->extensionOptions($booking)->assertOk()
            ->assertJsonPath('data.can_extend', false)
            ->assertJsonPath('data.reason', 'space_disallows')
            ->assertJsonPath('data.options', []);
    }

    public function test_a_booking_nobody_checked_into_cannot_extend(): void
    {
        $booking = $this->inProgress($this->room(), ['checked_in_at' => null]);

        $this->extensionOptions($booking)->assertOk()
            ->assertJsonPath('data.can_extend', false)
            ->assertJsonPath('data.reason', 'not_checked_in');
    }

    public function test_a_booking_checked_in_early_cannot_extend_before_it_starts(): void
    {
        Carbon::setTestNow($this->at('09:55'));
        $booking = $this->inProgress($this->room(), ['checked_in_at' => $this->at('09:50')]);

        $this->extensionOptions($booking)->assertOk()
            ->assertJsonPath('data.can_extend', false)
            ->assertJsonPath('data.reason', 'outside_booking_time');
    }

    public function test_another_members_booking_is_not_theirs_to_read(): void
    {
        $booking = $this->inProgress($this->room(), ['user_id' => User::factory()->create()->id]);

        $this->extensionOptions($booking)->assertForbidden();
    }

    public function test_reception_reads_the_same_options(): void
    {
        $space = $this->room();
        $booking = $this->inProgress($space);
        $this->bookedFrom($space, '12:00', '13:00');

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/admin/reception/bookings/{$booking->id}/extension-options");

        $response->assertOk()->assertJsonPath('data.can_extend', true);
        $this->assertSame([60], array_column($response->json('data.options'), 'minutes'));
    }

    // ---------------------------------------------------------------
    // On each booking
    // ---------------------------------------------------------------

    /** On the booking's detail; the list card shows only what the card draws (MemberBookingCardTest). */
    public function test_each_booking_says_whether_it_can_extend(): void
    {
        $space = $this->room();
        $running = $this->inProgress($space);
        $later = $this->bookedFrom($space, '15:00', '16:00');
        $later->update(['user_id' => $this->member->id]);

        $canExtend = fn (Booking $booking) => $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->json('data.actions.can_extend');

        $this->assertTrue($canExtend($running));
        $this->assertFalse($canExtend($later));
    }

    public function test_the_detail_says_why_it_cannot_extend(): void
    {
        $booking = $this->inProgress($this->room(attributes: ['allows_extension' => false]));

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_extend', false)
            ->assertJsonPath('data.actions.extend_disabled_reason', __('api.booking.extension_reasons.space_disallows', [], 'en'));
    }

    // ---------------------------------------------------------------
    // The extension itself enforces the same rules
    // ---------------------------------------------------------------

    private function extend(Booking $booking, int $minutes): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/bookings/{$booking->id}/extend", [
            'additional_minutes' => $minutes,
        ]);
    }

    public function test_extending_on_a_space_that_does_not_allow_it_is_refused(): void
    {
        $booking = $this->inProgress($this->room(attributes: ['allows_extension' => false]));

        $this->extend($booking, 60)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.booking.extension_not_allowed', [], 'en'));

        $this->assertTrue($booking->fresh()->end_at->equalTo($this->at('11:00')));
    }

    public function test_extending_before_the_booking_starts_is_refused(): void
    {
        Carbon::setTestNow($this->at('09:55'));
        $booking = $this->inProgress($this->room(), ['checked_in_at' => $this->at('09:50')]);

        $this->extend($booking, 60)
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.booking.extension_outside_booking_time', [], 'en'));
    }

    public function test_extending_past_closing_is_refused(): void
    {
        $booking = $this->inProgress($this->room('12:00'));

        $this->extend($booking, 90)->assertUnprocessable();

        $this->assertTrue($booking->fresh()->end_at->equalTo($this->at('11:00')));
    }

    public function test_extending_within_the_offered_options_succeeds(): void
    {
        $space = $this->room();
        $booking = $this->inProgress($space);
        $this->bookedFrom($space, '13:00', '14:00');

        $this->extend($booking, 120)->assertOk();

        $this->assertTrue($booking->fresh()->end_at->equalTo($this->at('13:00')));
        $this->assertSame(BookingStatus::Confirmed, $booking->fresh()->status);
    }
}
