<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\ArrivalRequestPurpose;
use App\Domain\Booking\Enums\ArrivalRequestStatus;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * One kiosk code for every arrival: what the scan files for reception
 * depends on what the member has, and a coworking session starts only when
 * reception confirms it (docs/decisions/coworking-self-service-sessions.md).
 */
class CoworkingScanRoutingTest extends TestCase
{
    use InteractsWithCoworking, RefreshDatabase;

    private Space $space;

    private Kiosk $kiosk;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RoleSeeder::class, ResourceTypeSeeder::class]);
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc());
        $this->space = $this->coworkingSpace();
        [$this->kiosk, $this->secret] = $this->pairedKiosk($this->space);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scan(?Kiosk $kiosk = null, ?string $secret = null): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson(
            '/api/v1/member/scan',
            ['qr_value' => $this->qrFor($kiosk ?? $this->kiosk, $secret ?? $this->secret)],
        );
    }

    private function damascus(string $time): Carbon
    {
        return Carbon::parse('2026-08-17 '.$time, 'Asia/Damascus')->utc();
    }

    private function bookingOn(Space $space, User $member, string $start, string $end, array $attributes = []): Booking
    {
        return Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $space->id,
            'start_at' => $this->damascus($start),
            'end_at' => $this->damascus($end),
        ] + $attributes);
    }

    private function roomInSameBranch(): Space
    {
        return Space::factory()->room()->create(['building_id' => $this->space->building_id]);
    }

    /** A member the plan covers, scanned in and confirmed by reception. */
    private function startedSession(User $member): void
    {
        $this->scan()->assertCreated();
        $this->confirmAtReception($member)->assertOk();
    }

    public function test_a_scan_files_a_coworking_start_and_nothing_starts(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation')
            ->assertJsonPath('data.arrival_request.purpose', 'coworking_start')
            ->assertJsonPath('data.arrival_request.upcoming_booking', null);

        $request = ArrivalRequest::sole();
        $this->assertSame(ArrivalRequestPurpose::CoworkingStart, $request->purpose);
        $this->assertSame($this->kiosk->id, $request->kiosk_id);
        $this->assertSame($this->space->id, $request->space_id);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_reception_sees_the_coworking_start_with_its_space_and_plan(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $this->scan()->assertCreated();

        $this->actingAsOperations();
        $this->getJson('/api/v1/admin/reception/arrival-requests')
            ->assertOk()
            ->assertJsonPath('data.0.purpose', 'coworking_start')
            ->assertJsonPath('data.0.user.id', $member->id)
            ->assertJsonPath('data.0.space.id', $this->space->id)
            ->assertJsonPath('data.0.coverage.status', 'covered');
    }

    public function test_scanning_with_a_session_already_open_is_refused(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $this->startedSession($member);
        $this->travel(5)->minutes();

        $this->scan()
            ->assertStatus(409)
            ->assertExactJson(['message' => 'You already have an active session. End it before starting a new one.']);
        $this->assertSame(1, WalkinSession::count());
    }

    public function test_a_room_booking_about_to_start_tells_reception_instead_of_opening_a_session(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $room = $this->roomInSameBranch();
        $booking = $this->bookingOn($room, $member, '10:30', '11:30');

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'arrival_requested')
            ->assertJsonPath('data.arrival_request.purpose', 'arrival')
            ->assertJsonPath('data.arrival_request.status', 'pending')
            ->assertJsonPath('data.arrival_request.matched_booking_id', $booking->id)
            ->assertJsonPath('data.booking.id', $booking->id)
            ->assertJsonPath('data.booking.space.id', $room->id)
            ->assertJsonPath('data.booking.start_at', $booking->start_at->toIso8601String());

        $request = ArrivalRequest::sole();
        $this->assertSame(ArrivalRequestStatus::Pending, $request->status);
        $this->assertSame(ArrivalRequestPurpose::Arrival, $request->purpose);
        $this->assertSame(0, WalkinSession::count());
        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_a_room_booking_already_running_tells_reception_too(): void
    {
        $member = $this->actingAsMember();
        $booking = $this->bookingOn($this->roomInSameBranch(), $member, '09:30', '11:00');

        $this->scan()->assertCreated()->assertJsonPath('data.booking.id', $booking->id);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_scanning_again_returns_the_request_already_waiting(): void
    {
        $member = $this->actingAsMember();
        $this->bookingOn($this->roomInSameBranch(), $member, '10:30', '11:30');

        $id = $this->scan()->assertCreated()->json('data.arrival_request.id');
        $this->scan()->assertOk()->assertJsonPath('data.outcome', 'arrival_requested')->assertJsonPath('data.arrival_request.id', $id);

        $this->assertSame(1, ArrivalRequest::count());
    }

    public function test_a_room_booking_hours_away_does_not_stop_a_coworking_session(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $this->bookingOn($this->roomInSameBranch(), $member, '15:00', '16:00');

        $this->scan()->assertCreated()->assertJsonPath('data.outcome', 'awaiting_confirmation');
        $this->assertSame(ArrivalRequestPurpose::CoworkingStart, ArrivalRequest::sole()->purpose);
    }

    public function test_a_room_booking_in_another_branch_is_ignored(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $this->bookingOn(Space::factory()->room()->create(), $member, '10:30', '11:30');

        $this->scan()->assertCreated()->assertJsonPath('data.outcome', 'awaiting_confirmation');
    }

    public function test_an_early_arrival_for_a_coworking_booking_sits_until_it_starts(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $booking = $this->bookingOn($this->space, $member, '11:00', '13:00');

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation')
            ->assertJsonPath('data.arrival_request.upcoming_booking.id', $booking->id)
            ->assertJsonPath('data.arrival_request.upcoming_booking.start_at', $booking->start_at->toIso8601String());

        $this->confirmAtReception($member)->assertOk();

        $this->assertSame($booking->id, WalkinSession::sole()->handover_booking_id);
        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_an_early_arrival_confirmed_once_the_booking_has_started_checks_the_booking_in(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $booking = $this->bookingOn($this->space, $member, '11:00', '13:00');
        $this->scan()->assertCreated();

        Carbon::setTestNow($this->damascus('11:00'));
        $this->confirmAtReception($member)->assertOk();

        $this->assertSame(0, WalkinSession::count());
        $this->assertTrue($booking->fresh()->checked_in_at->equalTo($booking->start_at));
    }

    public function test_the_timer_shows_the_booking_the_early_session_hands_over_to(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $booking = $this->bookingOn($this->space, $member, '11:00', '13:00');
        $this->startedSession($member);

        $this->getJson('/api/v1/member/coworking-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.upcoming_booking.id', $booking->id);
    }

    public function test_an_early_arrival_with_no_free_seat_is_told_when_to_come_back(): void
    {
        $space = $this->coworkingSpace(capacity: 1);
        [$kiosk, $secret] = $this->pairedKiosk($space);
        WalkinSession::factory()->create(['space_id' => $space->id]);
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $this->bookingOn($space, $member, '11:00', '13:00');

        $this->scan($kiosk, $secret)
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Your booking starts at 11:00. There is no free seat before then; you can scan from 11:00.']);
        $this->assertSame(0, ArrivalRequest::count());
    }

    public function test_the_early_session_hands_over_to_the_booking_when_it_starts(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $booking = $this->bookingOn($this->space, $member, '11:00', '13:00');
        $this->startedSession($member);

        Carbon::setTestNow($this->damascus('11:00:30'));
        $this->artisan('coworking:hand-over-early-sessions')->assertSuccessful();

        $walkin = WalkinSession::sole();
        $this->assertTrue($walkin->checked_out_at->equalTo($booking->start_at));
        $this->assertSame(TerminationSource::HandedOver, $walkin->termination_source);
        $this->assertSame(3600, $walkin->plan_covered_seconds);

        $booking->refresh();
        $this->assertTrue($booking->checked_in_at->equalTo($booking->start_at));
        $this->assertSame(SessionStartSource::Scan, $booking->checked_in_via);
        $this->assertSame($this->kiosk->id, $booking->kiosk_id);

        $this->getJson('/api/v1/member/coworking-sessions/current')
            ->assertJsonPath('data.type', 'booking')
            ->assertJsonPath('data.id', $booking->id);
    }

    public function test_nothing_is_handed_over_before_the_booking_starts(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $booking = $this->bookingOn($this->space, $member, '11:00', '13:00');
        $this->startedSession($member);

        Carbon::setTestNow($this->damascus('10:59'));
        $this->artisan('coworking:hand-over-early-sessions')->assertSuccessful();

        $this->assertNull(WalkinSession::sole()->checked_out_at);
        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_a_booking_cancelled_meanwhile_leaves_the_session_running(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $booking = $this->bookingOn($this->space, $member, '11:00', '13:00');
        $this->startedSession($member);
        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

        Carbon::setTestNow($this->damascus('11:01'));
        $this->artisan('coworking:hand-over-early-sessions')->assertSuccessful();

        $walkin = WalkinSession::sole();
        $this->assertNull($walkin->checked_out_at);
        $this->assertNull($walkin->handover_booking_id);
        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_a_session_the_member_asked_to_end_is_not_handed_over(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $booking = $this->bookingOn($this->space, $member, '11:00', '13:00');
        $this->startedSession($member);
        $walkin = WalkinSession::sole();
        $walkin->endRequests()->create([
            'user_id' => $member->id,
            'status' => SessionEndRequestStatus::Pending,
            'requested_at' => $this->damascus('10:50'),
        ]);

        Carbon::setTestNow($this->damascus('11:01'));
        $this->artisan('coworking:hand-over-early-sessions')->assertSuccessful();

        $walkin->refresh();
        $this->assertNull($walkin->checked_out_at);
        $this->assertNull($walkin->handover_booking_id);
        $this->assertNull($booking->fresh()->checked_in_at);
    }
}
