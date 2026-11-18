<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCheckInService;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * Arriving before a coworking booking is a walk-in billed from the arrival,
 * handed over to the booking at its start (product owner, 2026-11-02).
 * Rooms keep their fifteen minutes.
 */
class CoworkingEarlyArrivalTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_coworking_booking_cannot_be_checked_in_before_it_starts(): void
    {
        $booking = Booking::factory()->create([
            'space_id' => $this->coworkingSpace()->id,
            'start_at' => now()->addMinutes(5),
            'end_at' => now()->addHours(2),
        ]);

        try {
            app(BookingCheckInService::class)->checkIn($booking);
            $this->fail('Checked a coworking booking in before its start');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.check_in_too_early', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertNull($booking->refresh()->checked_in_at);
    }

    public function test_a_coworking_booking_checks_in_once_it_has_started(): void
    {
        $booking = Booking::factory()->create([
            'space_id' => $this->coworkingSpace()->id,
            'start_at' => now(),
            'end_at' => now()->addHours(2),
        ]);

        app(BookingCheckInService::class)->checkIn($booking);

        $this->assertTrue($booking->refresh()->checked_in_at->equalTo(now()));
    }

    public function test_a_room_booking_still_checks_in_up_to_fifteen_minutes_early(): void
    {
        $room = Space::factory()->create(['space_type' => SpaceType::Room, 'building_id' => $this->coworkingSpace()->building_id]);
        $booking = Booking::factory()->create([
            'space_id' => $room->id,
            'start_at' => now()->addMinutes(10),
            'end_at' => now()->addHour(),
        ]);

        app(BookingCheckInService::class)->checkIn($booking);

        $this->assertTrue($booking->refresh()->checked_in_at->equalTo($booking->start_at));
    }

    public function test_a_reception_walk_in_links_the_members_later_booking(): void
    {
        $space = $this->coworkingSpace();
        $member = User::factory()->create();
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(4),
        ]);

        $session = app(WalkInCapacityService::class)->start($space, $member)->session;

        $this->assertSame($booking->id, $session->refresh()->handover_booking_id);
    }

    public function test_a_walk_in_with_no_later_booking_links_nothing(): void
    {
        $space = $this->coworkingSpace();

        $session = app(WalkInCapacityService::class)->start($space, User::factory()->create())->session;

        $this->assertNull($session->refresh()->handover_booking_id);
    }

    public function test_a_pending_booking_is_not_linked(): void
    {
        $space = $this->coworkingSpace();
        $member = User::factory()->create();
        Booking::factory()->pending()->create([
            'space_id' => $space->id,
            'user_id' => $member->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(4),
        ]);

        $session = app(WalkInCapacityService::class)->start($space, $member)->session;

        $this->assertNull($session->refresh()->handover_booking_id);
    }

    public function test_a_booking_on_another_coworking_space_is_not_linked(): void
    {
        $space = $this->coworkingSpace();
        $other = Space::factory()->create(['building_id' => $space->building_id]);
        $member = User::factory()->create();
        Booking::factory()->create([
            'space_id' => $other->id,
            'user_id' => $member->id,
            'start_at' => now()->addHours(2),
            'end_at' => now()->addHours(4),
        ]);

        $session = app(WalkInCapacityService::class)->start($space, $member)->session;

        $this->assertNull($session->refresh()->handover_booking_id);
    }

    public function test_a_scan_five_minutes_before_a_coworking_booking_files_a_walk_in_that_hands_over(): void
    {
        $space = $this->coworkingSpace();
        [$kiosk, $secret] = $this->pairedKiosk($space);
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => now()->addMinutes(5),
            'end_at' => now()->addHours(2),
        ]);

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/scan', ['qr_value' => $this->qrFor($kiosk, $secret)])
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation');

        $request = ArrivalRequest::query()->latest('id')->firstOrFail();
        $this->assertNull($request->matched_booking_id);
        $this->assertSame($booking->id, $request->handover_booking_id);
    }
}
