<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\Kiosk;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\KioskPairingService;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Membership\Services\ResourceQuotaService;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
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
            '/api/v1/member/coworking-sessions',
            $body ?? ['qr_value' => $this->qrFor($this->kiosk, $this->secret)],
        );
    }

    private function accept(string $ticket): TestResponse
    {
        return $this->scan(['scan_ticket' => $ticket, 'accept_uncovered' => true]);
    }

    public function test_a_member_the_plan_covers_starts_at_once(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));

        $this->scan()
            ->assertCreated()
            ->assertJsonPath('data.type', 'walkin')
            ->assertJsonPath('data.started_via', 'scan')
            ->assertJsonPath('data.coverage.status', 'covered')
            ->assertJsonPath('data.accepted_uncovered', false);

        $session = WalkinSession::sole();
        $this->assertSame($member->id, $session->user_id);
        $this->assertSame($this->kiosk->id, $session->kiosk_id);
    }

    public function test_a_member_without_a_plan_is_asked_first_and_nothing_starts(): void
    {
        $this->actingAsMember();

        $response = $this->scan();

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'no_plan')
            ->assertJsonPath('hourly_rate_points', 100)
            ->assertJsonPath('message', 'Your plan will not cover this session (you have no active plan). It will be billed at 100 points per hour.');
        $this->assertNotEmpty($response->json('scan_ticket'));
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_accepting_with_the_ticket_starts_a_paid_session(): void
    {
        $this->actingAsMember();

        $this->accept($this->scan()->json('scan_ticket'))
            ->assertCreated()
            ->assertJsonPath('data.accepted_uncovered', true)
            ->assertJsonPath('data.coverage.reason', 'no_plan');
    }

    public function test_accepting_the_same_ticket_twice_does_not_start_a_second_session(): void
    {
        $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');

        $id = $this->accept($ticket)->assertCreated()->json('data.id');
        $this->accept($ticket)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, WalkinSession::count());
    }

    public function test_a_space_without_an_hourly_rate_still_asks_before_starting(): void
    {
        $this->actingAsMember();
        $this->space->forceFill(['hourly_rate_points' => null])->save();

        $response = $this->scan();

        $response->assertStatus(422)
            ->assertJsonPath('reason', 'no_plan')
            ->assertJsonPath('hourly_rate_points', 0);
        $this->assertNotEmpty($response->json('scan_ticket'));
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_ticket_for_a_kiosk_revoked_since_it_was_issued_is_refused(): void
    {
        $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');
        app(KioskPairingService::class)->revoke($this->kiosk);

        $this->accept($ticket)
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This code is not valid. Scan the code currently shown on the kiosk.']);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_ticket_for_a_kiosk_deactivated_since_it_was_issued_is_refused(): void
    {
        $this->actingAsMember();
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
        $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');

        $this->scan(['scan_ticket' => $ticket])->assertStatus(422)->assertJsonValidationErrors('accept_uncovered');
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_a_code_and_a_ticket_together_are_refused(): void
    {
        $this->actingAsMember();
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
        $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->travel(121)->seconds();

        $this->accept($ticket)->assertStatus(422)->assertExactJson(['message' => 'This confirmation has expired. Scan the kiosk code again.']);
    }

    public function test_another_members_ticket_is_refused(): void
    {
        $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->actingAsMember();

        $this->accept($ticket)->assertStatus(422);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_coverage_is_evaluated_again_when_the_member_accepts(): void
    {
        $member = $this->actingAsMember();
        $ticket = $this->scan()->json('scan_ticket');
        $this->subscribe($member, $this->planWithHours(40)); // renewed in between

        $this->accept($ticket)
            ->assertCreated()
            ->assertJsonPath('data.coverage.status', 'covered')
            ->assertJsonPath('data.accepted_uncovered', false);
    }

    public function test_todays_coworking_booking_is_checked_in_instead_of_a_walk_in(): void
    {
        $member = $this->actingAsMember();
        $booking = Booking::factory()->create([
            'user_id' => $member->id,
            'space_id' => $this->space->id,
            'start_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 13:00', 'Asia/Damascus')->utc(),
        ]);

        $this->scan()->assertCreated()->assertJsonPath('data.type', 'booking')->assertJsonPath('data.id', $booking->id);

        $booking->refresh();
        $this->assertNotNull($booking->checked_in_at);
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
            'start_at' => Carbon::parse('2026-08-17 11:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 13:00', 'Asia/Damascus')->utc(),
        ]);

        $this->scan()->assertStatus(409)->assertExactJson(['message' => 'Your booking is awaiting approval. Please see reception.']);
        $this->assertNull($booking->fresh()->checked_in_at);
        $this->assertSame(0, WalkinSession::count());
    }

    public function test_scanning_with_a_session_already_open_returns_it(): void
    {
        $member = $this->actingAsMember();
        $this->subscribe($member, $this->planWithHours(40));
        $id = $this->scan()->assertCreated()->json('data.id');
        $this->travel(5)->minutes();

        $this->scan()->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, WalkinSession::count());
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

    public function test_the_timer_endpoint_returns_the_open_session_or_null(): void
    {
        $member = $this->actingAsMember();
        $this->getJson('/api/v1/member/coworking-sessions/current')->assertOk()->assertJsonPath('data', null);

        $this->subscribe($member, $this->planWithHours(40));
        $id = $this->scan()->json('data.id');

        $this->getJson('/api/v1/member/coworking-sessions/current')
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.coverage.covered_until', Carbon::parse('2026-08-17 20:00', 'Asia/Damascus')->utc()->toIso8601String());
    }

    public function test_only_members_can_start_sessions(): void
    {
        $this->actingAsOperations();

        $this->postJson('/api/v1/member/coworking-sessions', ['qr_value' => 'x'])->assertForbidden();
    }
}
