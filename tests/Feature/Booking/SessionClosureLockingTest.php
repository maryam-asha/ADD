<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\TerminationSource;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Membership\Models\ResourceConsumption;
use Carbon\Carbon;
use Database\Seeders\ResourceTypeSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\InteractsWithCoworking;
use Tests\TestCase;

/**
 * SessionClosureService::closeOut() locks and re-reads the session before
 * deciding anything, like autoClose(): the desk checkout, a member-confirmed
 * end and the overdue job can all reach the same session, and a caller's
 * instance may already be stale by the time it gets here.
 */
class SessionClosureLockingTest extends TestCase
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

    public function test_a_stale_instance_cannot_close_a_session_a_second_time(): void
    {
        $closures = app(SessionClosureService::class);
        $member = $this->actingAsMember();
        $membership = $this->subscribe($member, $this->planWithHours(40));
        $session = WalkinSession::factory()->create([
            'space_id' => $this->coworkingSpace()->id,
            'user_id' => $member->id,
            'membership_id' => $membership->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->utc(),
        ]);

        // Loaded before the other close lands, as a desk checkout's route
        // binding would be when a confirmation or auto-close races it.
        $stale = WalkinSession::find($session->id);

        $closures->closeOut(WalkinSession::find($session->id), now());

        $closed = $session->fresh();
        $this->assertSame(1, ResourceConsumption::count()); // the plan absorbed the hour once

        try {
            $closures->closeOut($stale, now()->subMinutes(30));
            $this->fail('Expected a ReceptionActionException — a stale instance must not close the session again.');
        } catch (ReceptionActionException $e) {
            $this->assertSame('api.reception.already_checked_out', $e->messageKey);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(1, ResourceConsumption::count());
        $session->refresh();
        $this->assertTrue($closed->checked_out_at->equalTo($session->checked_out_at));
        $this->assertSame((string) $closed->amount_owed, (string) $session->amount_owed);
        $this->assertSame($closed->plan_covered_seconds, $session->plan_covered_seconds);
    }

    public function test_the_callers_instance_reflects_the_closure(): void
    {
        $session = WalkinSession::factory()->create([
            'space_id' => $this->coworkingSpace()->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->utc(),
        ]);

        app(SessionClosureService::class)->closeOut($session, now());

        $this->assertNotNull($session->checked_out_at);
        $this->assertSame(TerminationSource::Reception, $session->termination_source);
        $this->assertSame('10', (string) $session->amount_owed);
    }

    public function test_the_desk_checkouts_still_audit_the_amount_owed(): void
    {
        $this->actingAsOperations();
        $space = $this->coworkingSpace();
        $walkIn = WalkinSession::factory()->create([
            'space_id' => $space->id,
            'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')->utc(),
        ]);
        $booking = Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->utc(),
            'checked_in_at' => Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus')->utc(),
        ]);
        $checkOut = ['checked_out_at' => Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus')->toIso8601String()];

        $this->postJson("/api/v1/admin/reception/walk-ins/{$walkIn->id}/check-out", $checkOut)->assertOk();
        $this->postJson("/api/v1/admin/reception/bookings/{$booking->id}/check-out", $checkOut)->assertOk();

        $this->assertSame('10', Activity::where('description', 'walkin_session_checked_out')->sole()->properties->get('amount_owed'));
        $this->assertSame('20', Activity::where('description', 'booking_checked_out')->sole()->properties->get('amount_owed'));
    }
}
