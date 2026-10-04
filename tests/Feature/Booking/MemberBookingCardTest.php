<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One card of GET member/bookings carries what the card shows and nothing
 * more: the space's title, type and picture, the day and the time, the status
 * badge, and whether it is paid. Everything else — the breakdown, the payment
 * details, cancel and extend — is the detail's.
 */
class MemberBookingCardTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // A Monday morning, district time.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc());

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);

        $this->room = Space::factory()->room()->create([
            'name' => ['en' => 'Meeting Room A', 'ar' => 'غرفة اجتماعات أ'],
            'images' => ['spaces/room-a.jpg'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function booking(string $date, int $from, int $to, array $attributes = []): Booking
    {
        $day = Carbon::parse($date, 'Asia/Damascus');

        return Booking::factory()->create(array_merge([
            'space_id' => $this->room->id,
            'user_id' => $this->member->id,
            'start_at' => $day->copy()->setTime($from, 0)->utc(),
            'end_at' => $day->copy()->setTime($to, 0)->utc(),
        ], $attributes));
    }

    /**
     * @return array<string, mixed>
     */
    private function card(string $lang = 'en'): array
    {
        return $this->withHeader('lang', $lang)
            ->getJson('/api/v1/member/bookings')
            ->assertOk()
            ->json('data.0');
    }

    public function test_a_card_carries_only_what_the_card_shows(): void
    {
        $booking = $this->booking('2026-08-17', 14, 15);

        $card = $this->card();

        $this->assertSame(['id', 'space', 'date_label', 'time_label', 'is_upcoming', 'status', 'status_label', 'payment_state'], array_keys($card));
        $this->assertSame(['title', 'main_image'], array_keys($card['space']));
        $this->assertSame($booking->id, $card['id']);
        $this->assertSame('Meeting Room A', $card['space']['title']);
        $this->assertSame('confirmed', $card['status']);
        $this->assertSame('Confirmed', $card['status_label']);
    }

    /**
     * A space created before `name` was wired up has none. The card titles it
     * the way the space browser always has, so one space never reads one way
     * there and blank here.
     */
    public function test_an_unnamed_space_takes_the_title_the_space_browser_gives_it(): void
    {
        $this->room->forceFill(['name' => null])->save();
        $coSpace = Space::factory()->create(['name' => null]);
        $this->booking('2026-08-17', 14, 15);
        $this->booking('2026-08-17', 16, 17, ['space_id' => $coSpace->id]);

        $titles = array_column(
            $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings')->assertOk()->json('data'),
            'space',
        );

        $this->assertEqualsCanonicalizing(['Meeting Room A', 'Hot Desk'], array_column($titles, 'title'));
    }

    public function test_the_detail_titles_an_unnamed_space_the_same_way(): void
    {
        $this->room->forceFill(['name' => null])->save();
        $booking = $this->booking('2026-08-17', 14, 15);

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.space.title', 'Meeting Room A');
    }

    public function test_the_day_is_named_today_and_tomorrow_and_dated_otherwise(): void
    {
        $this->booking('2026-08-17', 14, 15);
        $this->assertSame('Today, August 17, 2026', $this->card()['date_label']);

        Booking::query()->delete();
        $this->booking('2026-08-18', 9, 13);
        $this->assertSame('Tomorrow, August 18, 2026', $this->card()['date_label']);

        Booking::query()->delete();
        $this->booking('2026-08-24', 10, 16);
        $this->assertSame('August 24, 2026', $this->card()['date_label']);
    }

    public function test_the_day_is_named_in_the_requested_language(): void
    {
        $this->booking('2026-08-17', 14, 15);

        $this->assertSame('اليوم، 17 أغسطس 2026', $this->card('ar')['date_label']);
    }

    /** Upcoming until its start — not its end: a booking under way is no longer upcoming. */
    public function test_a_booking_is_upcoming_until_it_starts(): void
    {
        // now() is 08:00 (setUp).
        $this->booking('2026-08-17', 9, 10);
        $this->assertTrue($this->card()['is_upcoming']);

        Booking::query()->delete();
        $this->booking('2026-08-17', 7, 9);
        $this->assertFalse($this->card()['is_upcoming']);

        Booking::query()->delete();
        $this->booking('2026-08-16', 9, 10, ['status' => BookingStatus::Completed]);
        $this->assertFalse($this->card()['is_upcoming']);
    }

    public function test_the_time_is_the_window_and_its_length(): void
    {
        $this->booking('2026-08-17', 13, 14);

        $this->assertSame('01:00 PM - 02:00 PM (1h)', $this->card()['time_label']);
    }

    public function test_the_card_says_whether_the_booking_is_paid(): void
    {
        $this->booking('2026-08-17', 14, 15, ['payment_state' => PaymentState::Paid]);
        $this->assertSame('paid', $this->card()['payment_state']);

        Booking::query()->delete();
        $this->booking('2026-08-17', 14, 15, ['status' => BookingStatus::Pending]);
        $this->assertSame('unpaid', $this->card()['payment_state']);
    }

    /** Ten cards cost what one does: the list reads the space and nothing else per card. */
    public function test_the_list_does_not_query_per_card(): void
    {
        foreach (range(9, 18) as $hour) {
            $this->booking('2026-08-17', $hour, $hour + 1);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->withHeader('lang', 'en')->getJson('/api/v1/member/bookings')->assertOk();

        $this->assertLessThanOrEqual(12, $queries, "The list issued {$queries} queries for ten bookings.");
    }
}
