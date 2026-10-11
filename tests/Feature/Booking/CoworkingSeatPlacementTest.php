<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Booking\Services\CoworkingWindowService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Floor;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\Zone;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Where a co-working booking sits: the branch's own seats first, a room lent
 * to co-working once they run out, and somewhere else when that room goes
 * back to being a room (docs/decisions/coworking-seat-pool.md, "Seating
 * placement").
 */
class CoworkingSeatPlacementTest extends TestCase
{
    use RefreshDatabase;

    private Space $coSpace;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->coSpace = Space::factory()->create([
            'space_type' => SpaceType::CoSpace,
            'is_lockable' => false,
            'capacity' => 1,
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 30,
        ]);

        BusinessHour::factory()->create([
            'branch_id' => $this->coSpace->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $time): Carbon
    {
        return Carbon::parse("2026-08-17 {$time}:00", 'Asia/Damascus')->utc();
    }

    /** A room in the co_space's branch, on floor 3, lent to co-working over [$from, $to). */
    private function lentRoom(string $from, string $to, int $seats = 2): Space
    {
        $floor = Floor::factory()->create(['building_id' => $this->coSpace->building_id, 'label' => '3']);
        $room = Space::factory()->room()->create([
            'building_id' => $this->coSpace->building_id,
            'zone_id' => Zone::factory()->create(['floor_id' => $floor->id])->id,
            'capacity' => 6,
        ]);

        SpaceCoworkingWindow::factory()->create([
            'space_id' => $room->id,
            'starts_at' => $this->at($from),
            'ends_at' => $this->at($to),
            'seats' => $seats,
        ]);

        return $room;
    }

    /** Someone else on the co_space's one base seat over [$from, $to). */
    private function baseTaken(string $from, string $to): void
    {
        Booking::factory()->create([
            'space_id' => $this->coSpace->id,
            'start_at' => $this->at($from),
            'end_at' => $this->at($to),
        ]);
    }

    private function book(string $from, string $to): Booking
    {
        return app(BookingCreationService::class)->create($this->coSpace, User::factory()->create(), $this->at($from), $this->at($to));
    }

    public function test_a_booking_sits_on_the_base_seats_while_they_last(): void
    {
        $this->lentRoom('09:00', '13:00');

        $this->assertNull($this->book('10:00', '11:00')->seat_space_id);
    }

    public function test_a_booking_goes_to_a_lent_room_once_the_base_seats_are_full(): void
    {
        $room = $this->lentRoom('09:00', '13:00');
        $this->baseTaken('10:00', '11:00');

        $this->assertSame($room->id, $this->book('10:00', '11:00')->seat_space_id);
    }

    public function test_a_lent_room_is_chosen_only_when_its_window_covers_the_whole_booking(): void
    {
        $this->lentRoom('09:00', '10:30', seats: 1);
        $covering = $this->lentRoom('09:00', '12:00', seats: 1);
        $this->baseTaken('10:00', '11:00');

        $this->assertSame($covering->id, $this->book('10:00', '11:00')->seat_space_id);
    }

    /** @return array{0: Booking, 1: Space} */
    private function runningInLentRoom(string $windowEnds): array
    {
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus'));
        $room = $this->lentRoom('10:00', $windowEnds);

        $booking = Booking::factory()->checkedIn()->create([
            'space_id' => $this->coSpace->id,
            'seat_space_id' => $room->id,
            'start_at' => $this->at('10:00'),
            'end_at' => $this->at('11:00'),
            'checked_in_at' => $this->at('10:00'),
        ]);

        return [$booking, $room];
    }

    public function test_an_extension_past_the_rooms_loan_moves_the_member_to_a_free_base_seat(): void
    {
        [$booking] = $this->runningInLentRoom('11:00');
        $this->baseTaken('10:00', '11:00');

        $movedTo = app(BookingExtensionService::class)->extend($booking, 60);

        $this->assertSame($this->coSpace->id, $movedTo?->id);
        $this->assertNull($booking->fresh()->seat_space_id);
        $this->assertTrue($booking->fresh()->end_at->equalTo($this->at('12:00')));
    }

    public function test_an_extension_inside_the_rooms_loan_keeps_the_member_where_they_are(): void
    {
        [$booking, $room] = $this->runningInLentRoom('13:00');

        $this->assertNull(app(BookingExtensionService::class)->extend($booking, 60));
        $this->assertSame($room->id, $booking->fresh()->seat_space_id);
    }

    public function test_an_extension_stops_where_the_rooms_loan_ends_when_no_other_seat_is_free(): void
    {
        [$booking] = $this->runningInLentRoom('11:00');
        $this->baseTaken('10:00', '13:00');

        try {
            app(BookingExtensionService::class)->extend($booking, 60);
            $this->fail('Expected the extension to stop at the end of the loan.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.booking.extension_seat_room_closes', $e->messageKey);
            $this->assertSame($this->at('11:00')->toIso8601String(), $e->params['latest_end_at']);
        }
    }

    public function test_taking_a_room_back_reseats_the_bookings_placed_in_it(): void
    {
        $room = $this->lentRoom('10:00', '13:00');
        $window = SpaceCoworkingWindow::where('space_id', $room->id)->firstOrFail();
        $booking = Booking::factory()->create([
            'space_id' => $this->coSpace->id,
            'seat_space_id' => $room->id,
            'start_at' => $this->at('11:00'),
            'end_at' => $this->at('12:00'),
        ]);

        app(CoworkingWindowService::class)->takeBack($window);

        $this->assertNull($booking->fresh()->seat_space_id);
    }

    public function test_the_booking_detail_tells_the_member_which_room_and_floor_to_head_to(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);

        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $room = $this->lentRoom('09:00', '13:00');
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->coSpace->id,
            'seat_space_id' => $room->id,
            'start_at' => $this->at('10:00'),
            'end_at' => $this->at('11:00'),
        ]);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.seat.space_id', $room->id)
            ->assertJsonPath('data.seat.floor', '3')
            ->assertJsonPath('data.space.id', $this->coSpace->id);
    }

    private function actingAsOperator(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
    }

    private function placedIn(Space $room, string $from, string $to, array $attributes = []): Booking
    {
        return Booking::factory()->create([
            'space_id' => $this->coSpace->id,
            'seat_space_id' => $room->id,
            'start_at' => $this->at($from),
            'end_at' => $this->at($to),
            ...$attributes,
        ]);
    }

    public function test_the_dashboard_pool_splits_the_seats_taken_now_by_place(): void
    {
        $this->actingAsOperator();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus'));
        $room = $this->lentRoom('10:00', '13:00');
        $this->baseTaken('10:00', '11:00');
        $this->placedIn($room, '10:00', '11:00');

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/branches/{$this->coSpace->building->branch_id}/coworking-pool")
            ->assertOk()
            ->assertJsonPath('data.usage_now', 2)
            ->assertJsonPath('data.places_now.0.kind', 'base')
            ->assertJsonPath('data.places_now.0.spaces.0.space_id', $this->coSpace->id)
            ->assertJsonPath('data.places_now.0.seats', 1)
            ->assertJsonPath('data.places_now.0.taken', 1)
            ->assertJsonPath('data.places_now.1.kind', 'lent_room')
            ->assertJsonPath('data.places_now.1.spaces.0.space_id', $room->id)
            ->assertJsonPath('data.places_now.1.spaces.0.floor', '3')
            ->assertJsonPath('data.places_now.1.seats', 2)
            ->assertJsonPath('data.places_now.1.taken', 1)
            ->assertJsonPath('data.places_now.1.free', 1)
            ->assertJsonPath('data.places_now.1.until', '2026-08-17T13:00:00+03:00');
    }

    public function test_a_lent_rooms_windows_say_how_full_it_is_now_and_at_its_busiest(): void
    {
        $this->actingAsOperator();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus'));
        $room = $this->lentRoom('10:00', '13:00');
        $this->placedIn($room, '10:00', '11:00');
        $this->placedIn($room, '11:00', '12:00');
        $this->placedIn($room, '11:00', '12:00');

        $this->getJson("/api/v1/admin/spaces/{$room->id}/coworking-windows")
            ->assertOk()
            ->assertJsonPath('data.0.taken_now', 1)
            ->assertJsonPath('data.0.taken_peak', 2);
    }

    public function test_operations_see_where_a_booking_and_a_checked_in_member_sit(): void
    {
        $this->actingAsOperator();
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:30:00', 'Asia/Damascus'));
        $room = $this->lentRoom('10:00', '13:00');
        $booking = $this->placedIn($room, '10:00', '11:00', ['checked_in_at' => $this->at('10:00')]);

        $this->getJson("/api/v1/admin/bookings/{$booking->id}")
            ->assertOk()
            ->assertJsonPath('data.seat.space_id', $room->id)
            ->assertJsonPath('data.seat.floor', '3');

        $this->getJson('/api/v1/admin/reception/sessions/active')
            ->assertOk()
            ->assertJsonPath('data.0.space_id', $this->coSpace->id)
            ->assertJsonPath('data.0.seat.space_id', $room->id);
    }
}
