<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\ArrivalRequest;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\KioskPairingService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Membership\Services\ResourceQuotaService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

class CoworkingSessionStartTest extends TestCase
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

    /** @param  array<string, mixed>|null  $body  defaults to scanning the kiosk's current code */
    private function scan(?array $body = null): TestResponse
    {
        return $this->withHeader('lang', 'en')->postJson(
            '/api/v1/member/scan',
            $body ?? ['qr_value' => $this->qrFor($this->kiosk, $this->secret)],
        );
    }

    private function accept(string $ticket): TestResponse
    {
        return $this->scan(['scan_ticket' => $ticket, 'accept_uncovered' => true]);
    }

    /** A member whose plan ended $daysAgo days ago: recent enough, by default, to be asked before an uncovered session. */
    private function actingAsLapsedMember(int $daysAgo = 3): User
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40))
            ->forceFill(['current_period_start' => now()->subDays($daysAgo + 30), 'current_period_end' => now()->subDays($daysAgo)])
            ->save();

        return $member;
    }

    public function test_a_member_the_plan_covers_waits_for_reception_and_starts_when_confirmed(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation')
            ->assertJsonPath('data.arrival_request.purpose', 'coworking_start')
            ->assertJsonPath('data.arrival_request.status', 'pending')
            ->assertJsonPath('data.arrival_request.space.id', $this->space->id)
            ->assertJsonPath('data.arrival_request.coverage.status', 'covered')
            ->assertJsonPath('data.arrival_request.accepted_uncovered', false)
            ->assertJsonPath('data.booking', null);
        $this->assertSame(0, WalkinSession::count());

        // Billed from reception's confirmation, not from the scan.
        $this->travel(3)->minutes();
        $this->confirmAtReception($member)->assertOk()->assertExactJson(['message' => 'Arrival confirmed.']);

        $session = WalkinSession::sole();
        $this->assertSame($member->id, $session->user_id);
        $this->assertSame($this->kiosk->id, $session->kiosk_id);
        $this->assertSame(SessionStartSource::Scan, $session->started_via);
        $this->assertFalse($session->accepted_uncovered);
        $this->assertTrue($session->checked_in_at->equalTo(now()));
    }

    public function test_a_member_without_a_plan_is_not_asked_and_the_paid_session_goes_to_reception(): void
    {
        $member = $this->actingAsMember();

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation')
            ->assertJsonPath('data.arrival_request.accepted_uncovered', true)
            ->assertJsonPath('data.arrival_request.coverage.reason', 'no_plan');
        $this->assertSame(0, WalkinSession::count());

        $this->confirmAtReception($member)->assertOk();

        $this->assertTrue(WalkinSession::sole()->accepted_uncovered);
    }

    public function test_a_plan_that_ended_over_fifteen_days_ago_is_not_asked_about(): void
    {
        $this->actingAsLapsedMember(daysAgo: 16);

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.arrival_request.accepted_uncovered', true)
            ->assertJsonPath('data.arrival_request.coverage.reason', 'plan_expired');
    }

    public function test_a_plan_that_ended_recently_is_explained_first_and_nothing_starts(): void
    {
        $this->actingAsLapsedMember(daysAgo: 14);

        $response = $this->scan();

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'plan_expired')
            ->assertJsonPath('hourly_rate_points', 100)
            ->assertJsonPath('message', 'Your plan will not cover this session (your plan has expired). It will be billed at 100 points per hour.');
        $this->assertNotEmpty($response->json('scan_ticket'));
        $this->assertSame(0, ArrivalRequest::count());
    }

    public function test_accepting_with_the_ticket_files_a_paid_session_for_reception(): void
    {
        $member = $this->actingAsLapsedMember();

        $this->accept($this->scan()->json('scan_ticket'))
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation')
            ->assertJsonPath('data.arrival_request.accepted_uncovered', true)
            ->assertJsonPath('data.arrival_request.coverage.reason', 'plan_expired');
        $this->assertSame(0, WalkinSession::count());

        $this->confirmAtReception($member)->assertOk();

        $this->assertTrue(WalkinSession::sole()->accepted_uncovered);
    }

    public function test_accepting_the_same_ticket_twice_files_one_request(): void
    {
        $this->actingAsLapsedMember();
        $ticket = $this->scan()->json('scan_ticket');

        $id = $this->accept($ticket)->assertCreated()->json('data.arrival_request.id');
        $this->accept($ticket)->assertOk()->assertJsonPath('data.arrival_request.id', $id);

        $this->assertSame(1, ArrivalRequest::count());
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_space_without_an_hourly_rate_still_asks_before_starting(): void
    {
        $this->actingAsLapsedMember();
        $this->space->forceFill(['hourly_rate_points' => null])->save();

        $response = $this->scan();

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'plan_expired')
            ->assertJsonPath('hourly_rate_points', 0);
        $this->assertNotEmpty($response->json('scan_ticket'));
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_ticket_for_a_kiosk_revoked_since_it_was_issued_is_refused(): void
    {
        $this->actingAsLapsedMember();
        $ticket = $this->scan()->json('scan_ticket');
        app(KioskPairingService::class)->revoke($this->kiosk);

        $this->accept($ticket)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This code is not valid. Scan the code currently shown on the kiosk.']);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_ticket_for_a_kiosk_deactivated_since_it_was_issued_is_refused(): void
    {
        $this->actingAsLapsedMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->kiosk->update(['is_active' => false]);

        $this->accept($ticket)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This code is not valid. Scan the code currently shown on the kiosk.']);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_tampered_ticket_is_treated_as_expired(): void
    {
        $this->actingAsMember();

        $this->accept('not-a-ticket')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This confirmation has expired. Scan the kiosk code again.']);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_ticket_without_the_explicit_acceptance_is_refused(): void
    {
        $this->actingAsLapsedMember();
        $ticket = $this->scan()->json('scan_ticket');

        $this->scan(['scan_ticket' => $ticket])->assertStatus(422)->assertJsonValidationErrors('accept_uncovered');
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_code_and_a_ticket_together_are_refused(): void
    {
        $this->actingAsLapsedMember();
        $ticket = $this->scan()->json('scan_ticket');

        $this->scan([
            'qr_value' => $this->qrFor($this->kiosk, $this->secret),
            'scan_ticket' => $ticket,
            'accept_uncovered' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('qr_value');
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_used_up_hours_are_explained_before_starting(): void
    {
        $member = $this->actingAsMember();
        $membership = $this->subscribe($member, $this->planWithHours(1));
        app(ResourceQuotaService::class)->consume(app(PlanHourCoverageService::class)->hourGrantFor($membership), $membership, 1.0, $member);

        $this->scan()
            ->assertStatus(422)
            ->assertJsonPath('reason', 'hours_exhausted')
            ->assertJsonPath('coverage.hours_remaining', 0);
    }

    public function test_the_ticket_expires_after_two_minutes(): void
    {
        $this->actingAsLapsedMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->travel(121)->seconds();

        $this->accept($ticket)->assertStatus(422)->assertExactJson(['message' => 'This confirmation has expired. Scan the kiosk code again.']);
    }

    public function test_another_members_ticket_is_refused(): void
    {
        $this->actingAsLapsedMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->actingAsMember();

        $this->accept($ticket)->assertStatus(422);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_coverage_is_evaluated_again_when_the_member_accepts(): void
    {
        $member = $this->actingAsLapsedMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->subscribe($member, $this->planWithHours(40)); // renewed in between

        $this->accept($ticket)
            ->assertCreated()
            ->assertJsonPath('data.arrival_request.coverage.status', 'covered')
            ->assertJsonPath('data.arrival_request.accepted_uncovered', false);
    }

    public function test_todays_coworking_booking_is_checked_in_instead_of_a_walk_in(): void
    {
        $member = $this->actingAsMember();
        // Ten minutes out: inside the early check-in window.
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'start_at' => Carbon::parse('2026-08-17 10:10', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 12:10', 'Asia/Damascus')->utc(),
        ]);

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.outcome', 'awaiting_confirmation')
            ->assertJsonPath('data.arrival_request.matched_booking_id', $booking->id)
            ->assertJsonPath('data.booking.id', $booking->id);
        $this->assertNull($booking->fresh()->checked_in_at);

        $this->confirmAtReception($member)->assertOk();

        $booking->refresh();
        // Confirmed before the booking starts: its session still begins at its start.
        $this->assertTrue($booking->checked_in_at->equalTo($booking->start_at));
        $this->assertSame(SessionStartSource::Scan, $booking->checked_in_via);
        $this->assertSame($this->kiosk->id, $booking->kiosk_id);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_booking_awaiting_approval_is_not_bypassed(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->pending()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'start_at' => Carbon::parse('2026-08-17 10:10', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 12:10', 'Asia/Damascus')->utc(),
        ]);

        $this->scan()->assertStatus(409)->assertExactJson(['message' => 'Your booking is awaiting approval. Please see reception.']);
        $this->assertNull($booking->fresh()->checked_in_at);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_booking_held_for_payment_is_not_checked_in(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'status' => BookingStatus::AwaitingPayment,
            'payment_due_at' => Carbon::parse('2026-08-17 10:10', 'Asia/Damascus')->utc(),
            'start_at' => Carbon::parse('2026-08-17 10:10', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 12:10', 'Asia/Damascus')->utc(),
        ]);

        $this->scan()->assertStatus(409)->assertExactJson(['message' => 'Your booking is waiting for its payment. Pay it in the app or at reception, then scan again.']);
        $this->assertNull($booking->fresh()->checked_in_at);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_booking_later_today_is_not_checked_in_early_and_a_walk_in_is_filed_instead(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'start_at' => Carbon::parse('2026-08-17 18:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 19:00', 'Asia/Damascus')->utc(),
        ]);

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.arrival_request.matched_booking_id', null)
            ->assertJsonPath('data.arrival_request.upcoming_booking.id', $booking->id)
            ->assertJsonPath('data.arrival_request.coverage.status', 'covered');

        $this->confirmAtReception($member)->assertOk();

        $this->assertNull($booking->fresh()->checked_in_at);
        $this->assertSame($booking->id, WalkinSession::sole()->handover_booking_id);
    }

    public function test_a_pending_booking_later_today_does_not_block_a_walk_in(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->pending()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'start_at' => Carbon::parse('2026-08-17 18:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 19:00', 'Asia/Damascus')->utc(),
        ]);

        // The walk-in path, not the booking's check-in.
        $this->scan()->assertCreated()->assertJsonPath('data.arrival_request.matched_booking_id', null);

        $this->assertNull($booking->fresh()->checked_in_at);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_kiosk_whose_space_is_under_maintenance_starts_nothing(): void
    {
        $this->actingAsMember();
        $this->space->forceFill(['status' => OperationalStatus::Maintenance])->save();

        $this->scan()->assertStatus(422)->assertExactJson(['message' => 'Coworking sessions are not available at this kiosk right now.']);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_an_invalid_code_is_refused(): void
    {
        $this->actingAsMember();

        $this->scan(['qr_value' => 'add-cowork:v1:'.$this->kiosk->id.':00000000'])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This code is not valid. Scan the code currently shown on the kiosk.']);
    }

    public function test_outside_business_hours_nothing_is_offered(): void
    {
        $this->actingAsMember();
        Carbon::setTestNow(Carbon::parse('2026-08-17 21:00:00', 'Asia/Damascus')->utc());

        $this->scan()->assertStatus(422)->assertExactJson(['message' => 'This action is not available outside business hours.']);
    }

    public function test_a_full_space_refuses_the_walk_in(): void
    {
        $space = $this->coworkingSpace(capacity: 1);
        [$kiosk, $secret] = $this->pairedKiosk($space);
        WalkinSession::factory()->create(['space_id' => $space->id]);
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));

        $this->scan(['qr_value' => $this->qrFor($kiosk, $secret)])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This space has no available capacity right now.']);
    }

    public function test_the_timer_endpoint_shows_the_wait_for_reception_then_the_session(): void
    {
        $member = $this->actingAsMember();
        $this->getJson('/api/v1/member/coworking-sessions/current')->assertOk()->assertJsonPath('data', null);

        $this->subscribe($member, $this->planWithHours(40));
        $requestId = $this->scan()->json('data.arrival_request.id');

        $this->getJson('/api/v1/member/coworking-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_confirmation')
            ->assertJsonPath('data.arrival_request.id', $requestId);

        $this->confirmAtReception($member)->assertOk();

        $this->getJson('/api/v1/member/coworking-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.state', 'active')
            ->assertJsonPath('data.id', WalkinSession::sole()->id)
            ->assertJsonPath('data.coverage.covered_until', Carbon::parse('2026-08-17 20:00', 'Asia/Damascus')->utc()->toIso8601String());
    }

    public function test_reception_rejecting_starts_nothing_and_the_member_can_scan_again(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $requestId = $this->scan()->json('data.arrival_request.id');

        $this->actingAsOperations();
        $this->postJson('/api/v1/admin/reception/arrival-requests/'.$requestId.'/reject')->assertOk();
        Sanctum::actingAs($member, ['*']);

        $this->assertSame(0, WalkinSession::count());
        $this->getJson('/api/v1/member/coworking-sessions/current')->assertJsonPath('data', null);
        $this->scan()->assertCreated()->assertJsonPath('data.outcome', 'awaiting_confirmation');
    }

    public function test_a_plan_that_stopped_covering_before_reception_confirmed_is_not_billed_unasked(): void
    {
        $member = $this->actingAsMember();
        $membership = $this->subscribe($member, $this->planWithHours(40));
        $this->scan()->assertCreated();
        $membership->forceFill(['current_period_end' => now()->subMinute()])->save();

        $this->confirmAtReception($member)
            ->assertStatus(409)
            ->assertExactJson(['message' => "The member's plan no longer covers this session. Reject the request and ask them to scan again."]);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_capacity_is_checked_again_when_reception_confirms(): void
    {
        $space = $this->coworkingSpace(capacity: 1);
        [$kiosk, $secret] = $this->pairedKiosk($space);
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $this->scan(['qr_value' => $this->qrFor($kiosk, $secret)])->assertCreated();
        WalkinSession::factory()->create(['space_id' => $space->id]);

        $this->confirmAtReception($member)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This space has no available capacity right now.']);
        $this->assertSame(1, WalkinSession::count());
    }

    public function test_scanning_is_throttled_at_ten_a_minute(): void
    {
        $this->actingAsLapsedMember();

        foreach (range(1, 10) as $attempt) {
            $this->scan()->assertStatus(422);
        }

        $this->scan()->assertStatus(429);
    }

    public function test_only_members_can_start_sessions(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/member/scan', ['qr_value' => 'x'])->assertForbidden();
    }
}
