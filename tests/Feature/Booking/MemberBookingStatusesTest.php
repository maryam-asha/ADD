<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentSource;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How the app shows and filters a booking's status: the catalog of statuses
 * and tabs, the tab filters, and the note that tells two bookings with the
 * same status apart by what happened to the money
 * (docs/decisions/booking-outcomes-and-extension.md).
 */
class MemberBookingStatusesTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 12:00:00', 'Asia/Damascus')->utc());

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);

        $this->room = Space::factory()->room()->create(['hourly_rate_points' => 100]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(int $from, int $to, array $attributes = []): Booking
    {
        $day = Carbon::parse('2026-08-17', 'Asia/Damascus');

        return Booking::factory()->create(array_merge([
            'space_id' => $this->room->id,
            'user_id' => $this->member->id,
            'start_at' => $day->copy()->setTime($from, 0)->utc(),
            'end_at' => $day->copy()->setTime($to, 0)->utc(),
        ], $attributes));
    }

    // ---------------------------------------------------------------
    // The catalog
    // ---------------------------------------------------------------

    public function test_the_catalog_lists_every_status_in_the_requested_language(): void
    {
        $response = $this->withHeader('lang', 'ar')->getJson('/api/v1/member/bookings/statuses');

        $response->assertOk()
            ->assertJsonPath('data', [
                'statuses' => [
                    ['key' => 'awaiting_payment', 'label' => 'بانتظار الدفع'],
                    ['key' => 'pending', 'label' => 'قيد المراجعة'],
                    ['key' => 'confirmed', 'label' => 'مؤكد'],
                    ['key' => 'completed', 'label' => 'مكتمل'],
                    ['key' => 'no_show', 'label' => 'لم يتم الحضور'],
                    ['key' => 'rejected', 'label' => 'مرفوض'],
                    ['key' => 'cancelled', 'label' => 'ملغى'],
                ],
            ]);
    }

    // ---------------------------------------------------------------
    // Filtering on the status (user decision, 2026-10-03: no `filter` tabs)
    // ---------------------------------------------------------------

    /**
     * @return list<int>
     */
    private function listedIds(string $query = ''): array
    {
        return array_column(
            $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings'.$query)->assertOk()->json('data'),
            'id',
        );
    }

    public function test_the_list_filters_on_one_status(): void
    {
        $completed = $this->booking(8, 9, ['status' => BookingStatus::Completed]);
        $this->booking(9, 10, ['status' => BookingStatus::NoShow]);
        $this->booking(14, 15);

        $this->assertSame([$completed->id], $this->listedIds('?status=completed'));
    }

    /** One request for the app's "upcoming" tab: the two statuses it shows. */
    public function test_the_list_filters_on_several_statuses(): void
    {
        $pending = $this->booking(14, 15, ['status' => BookingStatus::Pending]);
        $confirmed = $this->booking(15, 16);
        $this->booking(16, 17, ['status' => BookingStatus::Cancelled]);
        $this->booking(8, 9, ['status' => BookingStatus::Completed]);

        $this->assertEqualsCanonicalizing([$pending->id, $confirmed->id], $this->listedIds('?status=pending,confirmed'));
    }

    public function test_without_a_status_every_booking_is_listed(): void
    {
        foreach ([BookingStatus::Pending, BookingStatus::Completed, BookingStatus::NoShow, BookingStatus::Cancelled] as $i => $status) {
            $this->booking(8 + $i, 9 + $i, ['status' => $status]);
        }

        $this->assertCount(4, $this->listedIds());
    }

    public function test_an_unknown_status_is_refused(): void
    {
        $this->getJson('/api/v1/member/bookings?status=archived')->assertUnprocessable();
        $this->getJson('/api/v1/member/bookings?status=pending,archived')->assertUnprocessable();
    }

    /** The old `filter` key is gone: sent, it filters nothing. */
    public function test_the_old_filter_key_no_longer_filters(): void
    {
        $this->booking(8, 9, ['status' => BookingStatus::Completed]);
        $this->booking(14, 15);

        $this->assertCount(2, $this->listedIds('?filter=completed'));
    }

    /**
     * What has not started comes first, soonest first, then the rest, latest
     * first — the design's "Upcoming" above "Completed", whatever the filter.
     */
    public function test_bookings_yet_to_start_come_first_then_the_rest_latest_first(): void
    {
        // now() is 12:00 (setUp).
        $laterToday = $this->booking(16, 17);
        $soon = $this->booking(13, 14);
        $morning = $this->booking(8, 9, ['status' => BookingStatus::Completed]);
        $running = $this->booking(11, 13);

        $this->assertSame([$soon->id, $laterToday->id, $running->id, $morning->id], $this->listedIds());
    }

    /** No per-tab counts (user decision, 2026-10-03): the page and its `meta`. */
    public function test_the_list_is_the_page_and_its_meta(): void
    {
        $this->booking(14, 15, ['status' => BookingStatus::Pending]);
        $this->booking(15, 16);
        $this->booking(16, 17, ['status' => BookingStatus::Cancelled]);

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings?status=pending,confirmed')->assertOk();

        $this->assertSame(['data', 'meta'], array_keys($response->json()));
        $response->assertJsonPath('meta.total', 2)->assertJsonPath('meta.status', ['pending', 'confirmed']);
    }

    // ---------------------------------------------------------------
    // The money note
    // ---------------------------------------------------------------

    private function noteFor(Booking $booking): ?string
    {
        return $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->json('data.status_note');
    }

    public function test_a_paid_no_show_says_the_points_are_kept(): void
    {
        $booking = $this->booking(9, 10, [
            'status' => BookingStatus::NoShow,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
            'price_points' => 100,
        ]);

        $this->assertSame(__('api.booking.status_notes.no_show_charged', [], 'en'), $this->noteFor($booking));
    }

    public function test_an_unpaid_no_show_says_nothing_is_owed(): void
    {
        $booking = $this->booking(9, 10, ['status' => BookingStatus::NoShow, 'price_points' => 100]);

        $this->assertSame(__('api.booking.status_notes.no_show_free', [], 'en'), $this->noteFor($booking));
    }

    public function test_a_completed_booking_left_unpaid_says_payment_is_due(): void
    {
        $booking = $this->booking(9, 10, ['status' => BookingStatus::Completed, 'price_points' => 100]);

        $this->assertSame(__('api.booking.status_notes.payment_due', [], 'en'), $this->noteFor($booking));
    }

    public function test_a_paid_booking_that_was_cancelled_says_the_points_came_back(): void
    {
        $booking = $this->booking(14, 15, [
            'status' => BookingStatus::Cancelled,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
            'price_points' => 100,
        ]);

        $this->assertSame(__('api.booking.status_notes.refunded', [], 'en'), $this->noteFor($booking));
    }

    public function test_a_booking_whose_money_is_unremarkable_has_no_note(): void
    {
        $paidAndDone = $this->booking(8, 9, [
            'status' => BookingStatus::Completed,
            'payment_state' => PaymentState::Paid,
            'payment_source' => PaymentSource::Wallet,
            'price_points' => 100,
        ]);
        $upcoming = $this->booking(14, 15, ['price_points' => 100]);

        $this->assertNull($this->noteFor($paidAndDone));
        $this->assertNull($this->noteFor($upcoming));
    }

    /** The card shows the badge and paid/unpaid; the note is the detail's (MemberBookingCardTest). */
    public function test_the_list_card_shows_the_outcome_and_leaves_the_note_to_the_detail(): void
    {
        $this->booking(9, 10, ['status' => BookingStatus::NoShow, 'price_points' => 100]);

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings?filter=all')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'no_show')
            ->assertJsonPath('data.0.status_label', 'Not Attended')
            ->assertJsonPath('data.0.payment_state', 'unpaid')
            ->assertJsonMissingPath('data.0.status_note');
    }

    // ---------------------------------------------------------------
    // The detail stepper
    // ---------------------------------------------------------------

    public function test_a_lapsed_request_explains_itself_in_the_viewers_language(): void
    {
        $booking = $this->booking(11, 12, [
            'status' => BookingStatus::Rejected,
            'approved_at' => now(),
        ]);

        $this->withHeader('lang', 'ar')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.request_status.rejection_reason', __('api.booking.lapsed_unreviewed', [], 'ar'));
    }

    public function test_a_completed_booking_shows_its_confirmation_as_done(): void
    {
        $booking = $this->booking(8, 9, ['status' => BookingStatus::Completed]);

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.request_status.steps.1.key', 'confirmed')
            ->assertJsonPath('data.request_status.steps.1.state', 'complete')
            ->assertJsonPath('data.request_status.steps.2.key', 'completed');
    }
}
