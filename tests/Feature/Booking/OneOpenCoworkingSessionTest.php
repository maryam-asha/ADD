<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * A member has at most one open coworking session, whichever way it was
 * started (docs/decisions/coworking-self-service-sessions.md): every member
 * endpoint reads "the" open session. The scan returns the existing one; the
 * reception paths — desk walk-in, arrival confirmation, desk booking
 * check-in — refuse a second with 409. Rooms and other space types are not
 * coworking sessions and are unaffected.
 */
class OneOpenCoworkingSessionTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private const ALREADY_IN_SESSION = 'This member already has an active coworking session.';

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());

        $this->member = User::factory()->create();
        $this->member->assignRole('member');

        // The member scanned in earlier this morning and is still there.
        WalkinSession::factory()->create([
            'space_id' => $this->coworkingSpace()->id,
            'user_id' => $this->member->id,
            'started_via' => SessionStartSource::Scan,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->utc(),
        ]);

        $this->actingAsOperations();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function openRoom(): Space
    {
        $room = Space::factory()->room()->create(['capacity' => 4, 'hourly_rate_points' => 100]);

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $room->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $room;
    }

    private function openSessionsOfMember(): int
    {
        return WalkinSession::query()->where('user_id', $this->member->id)->whereNull('checked_out_at')->count()
            + Booking::query()->where('user_id', $this->member->id)->whereNotNull('checked_in_at')->whereNull('checked_out_at')->count();
    }

    public function test_a_desk_walk_in_on_a_coworking_space_is_refused_for_a_member_already_in_a_session(): void
    {
        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $this->coworkingSpace()->id,
            'user_id' => $this->member->id,
        ])->assertStatus(409)->assertExactJson(['message' => self::ALREADY_IN_SESSION]);

        $this->assertSame(1, $this->openSessionsOfMember());
    }

    public function test_confirming_an_arrival_onto_a_coworking_space_is_refused_for_a_member_already_in_a_session(): void
    {
        $arrivalRequest = ArrivalRequest::factory()->create(['user_id' => $this->member->id]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/arrival-requests/{$arrivalRequest->id}/confirm", [
            'space_id' => $this->coworkingSpace()->id,
        ])->assertStatus(409)->assertExactJson(['message' => self::ALREADY_IN_SESSION]);

        $this->assertSame(ArrivalRequestStatus::Pending, $arrivalRequest->fresh()->status);
        $this->assertSame(1, $this->openSessionsOfMember());
    }

    public function test_a_desk_check_in_of_a_coworking_booking_is_refused_for_a_member_already_in_a_session(): void
    {
        $booking = Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->coworkingSpace()->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
        ]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in")
            ->assertStatus(409)
            ->assertExactJson(['message' => self::ALREADY_IN_SESSION]);

        $this->assertNull($booking->fresh()->checked_in_at);
        $this->assertSame(1, $this->openSessionsOfMember());
    }

    public function test_a_room_walk_in_still_starts_for_a_member_in_a_coworking_session(): void
    {
        $this->withHeader('lang', 'en')->postJson('/api/v1/admin/reception/walk-ins', [
            'space_id' => $this->openRoom()->id,
            'user_id' => $this->member->id,
        ])->assertCreated();

        $this->assertSame(2, $this->openSessionsOfMember());
    }

    public function test_a_room_booking_still_checks_in_for_a_member_in_a_coworking_session(): void
    {
        $booking = Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->openRoom()->id,
            'start_at' => Carbon::parse('2026-08-17 10:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 12:00', 'Asia/Damascus')->utc(),
        ]);

        $this->withHeader('lang', 'en')->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-in")
            ->assertOk()
            ->assertExactJson(['message' => 'Checked in.']);

        $this->assertNotNull($booking->fresh()->checked_in_at);
    }
}
