<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Support\Enums\EventRegistrationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A pending registration is free but holds a seat. These cover the two ways
 * it stops doing so: the admin closing the queue out, and the deadline.
 */
class EventPendingExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function member(): User
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        return $user;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    // ── The deadline ─────────────────────────────────────────────────────────

    public function test_a_pending_registration_gets_a_deadline_counted_back_from_the_event(): void
    {
        config(['events.pending_expiry_hours_before_start' => 24]);

        $event = Event::factory()->requiresApproval()->create([
            'start_at' => now()->addDays(10),
            'end_at' => now()->addDays(10)->addHours(2),
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();

        $attendee = EventAttendee::firstOrFail();

        $this->assertNotNull($attendee->pending_expires_at);
        $this->assertTrue(
            $attendee->pending_expires_at->equalTo($event->start_at->copy()->subHours(24)),
            'The deadline should sit 24 hours before the event starts.'
        );
    }

    public function test_an_immediately_approved_registration_carries_no_deadline(): void
    {
        $event = Event::factory()->create(['start_at' => now()->addDays(10)]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();

        $attendee = EventAttendee::firstOrFail();

        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertNull($attendee->pending_expires_at);
    }

    /** An event inside the window would otherwise be born already expired. */
    public function test_an_event_starting_within_the_window_mints_no_deadline(): void
    {
        config(['events.pending_expiry_hours_before_start' => 24]);

        $event = Event::factory()->requiresApproval()->create([
            'start_at' => now()->addHours(3),
            'end_at' => now()->addHours(5),
        ]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();

        $this->assertNull(EventAttendee::firstOrFail()->pending_expires_at);
    }

    // ── The sweep ────────────────────────────────────────────────────────────

    public function test_the_sweep_closes_out_registrations_past_their_deadline(): void
    {
        $event = Event::factory()->requiresApproval()->create();

        $overdue = EventAttendee::factory()->pending()->create([
            'event_id' => $event->id,
            'pending_expires_at' => now()->subHour(),
        ]);

        $stillLive = EventAttendee::factory()->pending()->create([
            'event_id' => $event->id,
            'pending_expires_at' => now()->addDay(),
        ]);

        $noDeadline = EventAttendee::factory()->pending()->create([
            'event_id' => $event->id,
            'pending_expires_at' => null,
        ]);

        $this->artisan('events:expire-pending-registrations')->assertSuccessful();

        $this->assertSame(EventRegistrationStatus::Rejected, $overdue->fresh()->status);
        $this->assertNotNull($overdue->fresh()->rejection_reason);
        // Nobody decided this one, so no operator is recorded against it.
        $this->assertNull($overdue->fresh()->approved_by);

        $this->assertSame(EventRegistrationStatus::Pending, $stillLive->fresh()->status);
        $this->assertSame(EventRegistrationStatus::Pending, $noDeadline->fresh()->status);
    }

    public function test_the_sweep_leaves_already_decided_registrations_alone(): void
    {
        $event = Event::factory()->requiresApproval()->create();

        $approved = EventAttendee::factory()->approved()->create([
            'event_id' => $event->id,
            'pending_expires_at' => now()->subDay(),
        ]);

        $this->artisan('events:expire-pending-registrations')->assertSuccessful();

        $this->assertSame(EventRegistrationStatus::Approved, $approved->fresh()->status);
    }

    public function test_an_expired_registration_releases_its_seat(): void
    {
        $event = Event::factory()->requiresApproval()->create(['capacity' => 1]);

        EventAttendee::factory()->pending()->create([
            'event_id' => $event->id,
            'pending_expires_at' => now()->subHour(),
        ]);

        // The seat is taken while the application stands.
        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertStatus(422);

        $this->artisan('events:expire-pending-registrations')->assertSuccessful();

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
    }

    // ── Bulk reject ──────────────────────────────────────────────────────────

    public function test_an_admin_can_close_out_the_whole_pending_queue_at_once(): void
    {
        $event = Event::factory()->requiresApproval()->create();

        EventAttendee::factory()->pending()->count(3)->create(['event_id' => $event->id]);
        $approved = EventAttendee::factory()->approved()->create(['event_id' => $event->id]);

        Sanctum::actingAs($this->admin(), ['*']);
        $response = $this->postJson("/api/v1/admin/events/{$event->id}/attendees/reject-pending", [
            'rejection_reason' => 'Review closed',
        ]);

        $response->assertOk();
        $this->assertSame(3, $response->json('rejected_count'));

        $this->assertSame(0, $event->attendees()->where('status', 'pending')->count());
        $this->assertSame(3, $event->attendees()->where('status', 'rejected')->count());
        // An approved registration is not part of the queue.
        $this->assertSame(EventRegistrationStatus::Approved, $approved->fresh()->status);
    }

    public function test_bulk_reject_records_the_reason_and_the_operator(): void
    {
        $event = Event::factory()->requiresApproval()->create();
        EventAttendee::factory()->pending()->create(['event_id' => $event->id]);

        $admin = $this->admin();
        Sanctum::actingAs($admin, ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/reject-pending", [
            'rejection_reason' => 'Venue changed',
        ])->assertOk();

        $attendee = $event->attendees()->firstOrFail();
        $this->assertSame('Venue changed', $attendee->rejection_reason);
        $this->assertSame($admin->id, $attendee->approved_by);
        $this->assertNotNull($attendee->approved_at);
    }

    public function test_bulk_reject_needs_a_reason(): void
    {
        $event = Event::factory()->requiresApproval()->create();
        EventAttendee::factory()->pending()->create(['event_id' => $event->id]);

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/reject-pending", [
            'rejection_reason' => '',
        ])->assertStatus(422);

        $this->assertSame(1, $event->attendees()->where('status', 'pending')->count());
    }

    public function test_bulk_reject_touches_only_the_event_it_was_called_on(): void
    {
        $eventA = Event::factory()->requiresApproval()->create();
        $eventB = Event::factory()->requiresApproval()->create();

        EventAttendee::factory()->pending()->create(['event_id' => $eventA->id]);
        $other = EventAttendee::factory()->pending()->create(['event_id' => $eventB->id]);

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/v1/admin/events/{$eventA->id}/attendees/reject-pending", [
            'rejection_reason' => 'Review closed',
        ])->assertOk();

        $this->assertSame(EventRegistrationStatus::Pending, $other->fresh()->status);
    }

    public function test_bulk_reject_on_an_empty_queue_is_a_no_op(): void
    {
        $event = Event::factory()->requiresApproval()->create();

        Sanctum::actingAs($this->admin(), ['*']);
        $response = $this->postJson("/api/v1/admin/events/{$event->id}/attendees/reject-pending", [
            'rejection_reason' => 'Review closed',
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('rejected_count'));
    }

    public function test_a_member_cannot_close_out_the_queue(): void
    {
        $event = Event::factory()->requiresApproval()->create();
        EventAttendee::factory()->pending()->create(['event_id' => $event->id]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/reject-pending", [
            'rejection_reason' => 'let me in',
        ])->assertForbidden();
    }
}
