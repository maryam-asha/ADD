<?php

namespace Tests\Feature\Member;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET member/home's `active_booking`: a room, office or hall booking's own
 * card, from its check-in window until it closes — never a coworking
 * booking, which is `active_session` — and, once closed and still owing,
 * its place in `unpaid`. The district runs on Asia/Damascus (+03:00).
 */
class MemberHomeBookingCardTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // 10:00 in the district.
        Carbon::setTestNow(Carbon::parse('2026-10-13 07:00:00', 'UTC'));

        $this->member = User::factory()->create();
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);

        $branch = Branch::factory()->create(['name' => ['en' => 'Main Branch', 'ar' => 'الفرع الرئيسي']]);
        $building = Building::factory()->create(['branch_id' => $branch->id]);
        $this->room = Space::factory()->room()->create([
            'building_id' => $building->id,
            'name' => ['en' => 'Meeting Room 03', 'ar' => 'قاعة الاجتماعات 03'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function home()
    {
        return $this->withHeader('lang', 'en')->getJson('/api/v1/member/home')->assertOk();
    }

    /** A confirmed booking of the room, 10:15–12:15 in the district unless told otherwise. */
    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->room->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => '2026-10-13 07:15:00',
            'end_at' => '2026-10-13 09:15:00',
            'price_points' => 200,
            'payment_state' => PaymentState::Unpaid,
            ...$attributes,
        ]);
    }

    public function test_a_room_booking_is_the_ready_card_from_fifteen_minutes_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 06:59:00', 'UTC'));
        $booking = $this->booking();

        $this->home()
            ->assertJsonPath('data.active_booking', null)
            ->assertJsonPath('data.today.0.id', $booking->id);

        Carbon::setTestNow(Carbon::parse('2026-10-13 07:00:00', 'UTC'));

        $this->home()
            ->assertJsonPath('data.active_booking', [
                'state' => 'ready',
                'id' => $booking->id,
                'reference' => $booking->reference,
                'space' => ['id' => $this->room->id, 'name' => 'Meeting Room 03', 'type' => 'room'],
                'branch' => ['id' => $this->room->building->branch_id, 'name' => 'Main Branch'],
                'start_at' => '2026-10-13T10:15:00+03:00',
                'end_at' => '2026-10-13T12:15:00+03:00',
                'checked_in_at' => null,
                'checked_in_via' => null,
            ])
            // The card above, so not listed again.
            ->assertJsonPath('data.today', []);
    }

    public function test_a_checked_in_room_is_the_active_card(): void
    {
        $booking = $this->booking([
            'start_at' => '2026-10-13 06:30:00',
            'end_at' => '2026-10-13 08:30:00',
            'checked_in_at' => '2026-10-13 06:40:00',
            'checked_in_via' => SessionStartSource::Door,
        ]);

        $this->home()
            ->assertJsonPath('data.active_booking.state', 'active')
            ->assertJsonPath('data.active_booking.id', $booking->id)
            ->assertJsonPath('data.active_booking.checked_in_at', '2026-10-13T09:40:00+03:00')
            ->assertJsonPath('data.active_booking.checked_in_via', 'door')
            ->assertJsonPath('data.active_session', null);
    }

    public function test_a_closed_room_still_owed_is_listed_with_its_method(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::Completed,
            'start_at' => '2026-10-13 04:00:00',
            'end_at' => '2026-10-13 06:00:00',
            'checked_in_at' => '2026-10-13 04:00:00',
            'checked_out_at' => '2026-10-13 06:00:00',
            'chosen_payment_method' => 'cash',
        ]);

        $response = $this->home()->assertJsonPath('data.active_booking', null);
        $item = $response->json('data.unpaid.0');

        $this->assertSame(['booking', 'booking', $booking->id, $booking->reference], [$item['kind'], $item['type'], $item['id'], $item['reference']]);
        $this->assertSame('2026-10-13T09:00:00+03:00', $item['checked_out_at']);
        $this->assertSame(['unpaid', 'cash', 200], [$item['payment']['state'], $item['payment']['method'], $item['payment']['amount_points']]);
    }

    /** Paid then extended: only the extension is owed. */
    public function test_a_closed_room_owes_only_what_was_not_paid(): void
    {
        $booking = $this->booking([
            'status' => BookingStatus::Completed,
            'start_at' => '2026-10-13 04:00:00',
            'end_at' => '2026-10-13 06:00:00',
            'checked_in_at' => '2026-10-13 04:00:00',
            'checked_out_at' => '2026-10-13 06:00:00',
            'price_points' => 300,
            'chosen_payment_method' => 'paymera',
        ]);
        Payment::factory()->forPayable($booking)->create(['purpose' => PaymentPurpose::Booking, 'status' => PaymentStatus::Accepted, 'settled_at' => now(), 'amount_points' => 200, 'user_id' => $this->member->id]);

        $this->home()
            ->assertJsonPath('data.unpaid.0.id', $booking->id)
            ->assertJsonPath('data.unpaid.0.payment.amount_points', 100)
            ->assertJsonPath('data.unpaid.0.payment.method', 'paymera');
    }

    public function test_a_paid_closed_room_a_no_show_or_a_cancelled_booking_is_neither_card_nor_owed(): void
    {
        $this->booking([
            'status' => BookingStatus::Completed,
            'start_at' => '2026-10-13 04:00:00',
            'end_at' => '2026-10-13 06:00:00',
            'checked_in_at' => '2026-10-13 04:00:00',
            'checked_out_at' => '2026-10-13 06:00:00',
            'payment_state' => PaymentState::Paid,
            'paid_at' => now(),
        ]);
        $this->booking(['status' => BookingStatus::NoShow, 'start_at' => '2026-10-13 04:00:00', 'end_at' => '2026-10-13 06:00:00']);
        $this->booking(['status' => BookingStatus::Cancelled]);

        $this->home()
            ->assertJsonPath('data.active_booking', null)
            ->assertJsonPath('data.unpaid', []);
    }

    public function test_a_coworking_booking_is_never_the_booking_card_and_a_room_never_the_session_card(): void
    {
        $openSpace = Space::factory()->create([
            'building_id' => $this->room->building_id,
            'space_type' => SpaceType::CoSpace,
        ]);
        $coworking = $this->booking([
            'space_id' => $openSpace->id,
            'start_at' => '2026-10-13 06:30:00',
            'checked_in_at' => '2026-10-13 06:30:00',
        ]);

        $this->home()
            ->assertJsonPath('data.active_booking', null)
            ->assertJsonPath('data.active_session.type', 'booking')
            ->assertJsonPath('data.active_session.id', $coworking->id);

        // Closed and paid, so it is no card at all.
        $coworking->forceFill(['checked_out_at' => now(), 'status' => BookingStatus::Completed, 'payment_state' => PaymentState::Paid, 'paid_at' => now()])->save();
        $room = $this->booking([
            'start_at' => '2026-10-13 06:30:00',
            'checked_in_at' => '2026-10-13 06:30:00',
        ]);

        $this->home()
            ->assertJsonPath('data.active_session', null)
            ->assertJsonPath('data.active_booking.id', $room->id);
    }

    /** The checked-in room is the card, and the older one it does not hide is still owed. */
    public function test_a_running_room_does_not_hide_one_still_owed(): void
    {
        $owed = $this->booking([
            'status' => BookingStatus::Completed,
            'start_at' => '2026-10-12 04:00:00',
            'end_at' => '2026-10-12 06:00:00',
            'checked_in_at' => '2026-10-12 04:00:00',
            'checked_out_at' => '2026-10-12 06:00:00',
        ]);
        $running = $this->booking([
            'start_at' => '2026-10-13 06:30:00',
            'checked_in_at' => '2026-10-13 06:30:00',
        ]);

        $this->home()
            ->assertJsonPath('data.active_booking.state', 'active')
            ->assertJsonPath('data.active_booking.id', $running->id)
            ->assertJsonCount(1, 'data.unpaid')
            ->assertJsonPath('data.unpaid.0.kind', 'booking')
            ->assertJsonPath('data.unpaid.0.id', $owed->id);
    }
}
