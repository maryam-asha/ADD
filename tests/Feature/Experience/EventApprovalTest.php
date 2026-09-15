<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Support\Enums\EventRegistrationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventApprovalTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->wallets = app(WalletService::class);
    }

    /** @return array{0: User, 1: Wallet} */
    private function member(int $points = 0): array
    {
        $user = User::factory()->create();
        $user->assignRole('member');

        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        if ($points > 0) {
            $this->wallets->creditGeneral($wallet, $points, WalletTransactionSource::TopUp, 'test');
        }

        return [$user, $wallet];
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function balance(Wallet $wallet): int
    {
        return (int) $this->wallets->generalBalance($wallet->fresh());
    }

    public function test_registering_for_an_event_that_needs_review_parks_it_as_pending(): void
    {
        [$user, $wallet] = $this->member(500);
        $event = Event::factory()->requiresApproval()->paid(150)->create();

        Sanctum::actingAs($user, ['*']);
        $response = $this->postJson("/api/v1/member/events/{$event->id}/register");

        $response->assertCreated();
        $this->assertSame('pending', $response->json('data.status'));
        $this->assertTrue($response->json('data.requires_approval'));

        $this->assertDatabaseHas('event_attendees', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        // Nothing is taken until someone approves.
        $this->assertSame(500, $this->balance($wallet));
    }

    public function test_approving_a_paid_registration_debits_at_that_moment(): void
    {
        [$user, $wallet] = $this->member(500);
        $event = Event::factory()->requiresApproval()->paid(150)->create();

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
        $attendee = EventAttendee::firstOrFail();

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/{$attendee->id}/approve")
            ->assertOk();

        $attendee->refresh();
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertNotNull($attendee->approved_by);
        $this->assertNotNull($attendee->approved_at);
        $this->assertSame(350, $this->balance($wallet));
    }

    /**
     * The whole point of debit-on-approve: if the member spends the points
     * while waiting, approval must fail cleanly rather than half-apply.
     */
    public function test_approval_fails_and_changes_nothing_when_the_balance_has_since_dropped(): void
    {
        [$user, $wallet] = $this->member(200);
        $event = Event::factory()->requiresApproval()->paid(150)->create();

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
        $attendee = EventAttendee::firstOrFail();

        // They spend it elsewhere before anyone reviews the request.
        $this->wallets->debitGeneral($wallet->fresh(), 180, 'spent elsewhere');
        $this->assertSame(20, $this->balance($wallet));

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/{$attendee->id}/approve")
            ->assertStatus(422);

        // Rolled back whole: still pending, wallet untouched.
        $this->assertSame(EventRegistrationStatus::Pending, $attendee->fresh()->status);
        $this->assertNull($attendee->fresh()->approved_at);
        $this->assertSame(20, $this->balance($wallet));
    }

    public function test_rejecting_stores_the_reason_and_refunds_nothing_because_nothing_was_taken(): void
    {
        [$user, $wallet] = $this->member(500);
        $event = Event::factory()->requiresApproval()->paid(150)->create();

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
        $attendee = EventAttendee::firstOrFail();

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/{$attendee->id}/reject", [
            'rejection_reason' => 'Not eligible this round',
        ])->assertOk();

        $attendee->refresh();
        $this->assertSame(EventRegistrationStatus::Rejected, $attendee->status);
        $this->assertSame('Not eligible this round', $attendee->rejection_reason);
        // Audit columns are written on rejection too.
        $this->assertNotNull($attendee->approved_by);
        $this->assertNotNull($attendee->approved_at);

        $this->assertSame(500, $this->balance($wallet));
    }

    public function test_rejecting_without_a_reason_is_refused(): void
    {
        [$user] = $this->member();
        $event = Event::factory()->requiresApproval()->create();

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
        $attendee = EventAttendee::firstOrFail();

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/{$attendee->id}/reject", [
            'rejection_reason' => '   ',
        ])->assertStatus(422);

        $this->assertSame(EventRegistrationStatus::Pending, $attendee->fresh()->status);
    }

    public function test_a_second_decision_on_the_same_registration_is_refused(): void
    {
        [$user] = $this->member();
        $event = Event::factory()->requiresApproval()->create();

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
        $attendee = EventAttendee::firstOrFail();

        Sanctum::actingAs($this->admin(), ['*']);
        $url = "/api/v1/admin/events/{$event->id}/attendees/{$attendee->id}/approve";

        $this->postJson($url)->assertOk();
        $this->postJson($url)->assertStatus(409);
    }

    public function test_approval_is_refused_once_the_event_has_filled_up(): void
    {
        $event = Event::factory()->requiresApproval()->create(['capacity' => 1]);

        [$pendingUser] = $this->member();
        Sanctum::actingAs($pendingUser, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
        $pending = EventAttendee::firstOrFail();

        // Someone else takes the only seat in the meantime.
        EventAttendee::factory()->approved()->create(['event_id' => $event->id]);

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/{$pending->id}/approve")
            ->assertStatus(409);

        $this->assertSame(EventRegistrationStatus::Pending, $pending->fresh()->status);
    }

    public function test_an_attendee_of_another_event_cannot_be_decided_through_this_one(): void
    {
        $eventA = Event::factory()->requiresApproval()->create();
        $eventB = Event::factory()->requiresApproval()->create();

        $attendee = EventAttendee::factory()->pending()->create(['event_id' => $eventB->id]);

        Sanctum::actingAs($this->admin(), ['*']);
        $this->postJson("/api/v1/admin/events/{$eventA->id}/attendees/{$attendee->id}/approve")
            ->assertStatus(404);

        $this->assertSame(EventRegistrationStatus::Pending, $attendee->fresh()->status);
    }

    public function test_a_member_cannot_approve_registrations(): void
    {
        [$user] = $this->member();
        $event = Event::factory()->requiresApproval()->create();
        $attendee = EventAttendee::factory()->pending()->create(['event_id' => $event->id]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/admin/events/{$event->id}/attendees/{$attendee->id}/approve")
            ->assertForbidden();
    }

    public function test_the_pending_queue_lists_only_undecided_registrations(): void
    {
        $event = Event::factory()->requiresApproval()->create();

        EventAttendee::factory()->pending()->count(2)->create(['event_id' => $event->id]);
        EventAttendee::factory()->approved()->create(['event_id' => $event->id]);
        EventAttendee::factory()->rejected()->create(['event_id' => $event->id]);

        Sanctum::actingAs($this->admin(), ['*']);
        $response = $this->getJson("/api/v1/admin/events/{$event->id}/attendees/pending");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_an_event_without_approval_still_admits_and_charges_immediately(): void
    {
        [$user, $wallet] = $this->member(500);
        $event = Event::factory()->paid(150)->create();

        Sanctum::actingAs($user, ['*']);
        $response = $this->postJson("/api/v1/member/events/{$event->id}/register");

        $response->assertCreated();
        $this->assertSame('approved', $response->json('data.status'));
        $this->assertSame(350, $this->balance($wallet));
    }
}
