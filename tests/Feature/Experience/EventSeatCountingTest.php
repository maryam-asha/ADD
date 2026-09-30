<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A rejected registration must not keep holding a seat. Capacity is read
 * in several places — the card, two raw SQL status filters, and the
 * registration check itself — so each is covered here.
 */
class EventSeatCountingTest extends TestCase
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

    public function test_a_rejected_registration_frees_its_seat_for_someone_else(): void
    {
        $event = Event::factory()->create(['capacity' => 1]);
        EventAttendee::factory()->rejected()->create(['event_id' => $event->id]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
    }

    public function test_a_pending_registration_keeps_holding_its_seat(): void
    {
        $event = Event::factory()->create(['capacity' => 1]);
        EventAttendee::factory()->pending()->create(['event_id' => $event->id]);

        Sanctum::actingAs($this->member(), ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")
            ->assertStatus(422)
            ->assertJsonValidationErrors('capacity');
    }

    public function test_the_card_counts_only_seat_holding_registrations(): void
    {
        $event = Event::factory()->create(['capacity' => 10]);
        EventAttendee::factory()->approved()->count(3)->create(['event_id' => $event->id]);
        EventAttendee::factory()->pending()->count(2)->create(['event_id' => $event->id]);
        EventAttendee::factory()->rejected()->count(4)->create(['event_id' => $event->id]);

        $response = $this->getJson('/api/v1/events');
        $card = collect($response->json('data'))->firstWhere('id', $event->id);

        // 3 approved + 2 pending; the 4 rejected do not count.
        $this->assertSame('5 seats left', $card['seats_left_text']);
        $this->assertArrayNotHasKey('attendees_count', $card);
        $this->assertArrayNotHasKey('seats_left', $card);
        $this->assertArrayNotHasKey('capacity', $card);
    }

    public function test_the_full_filter_ignores_rejected_registrations(): void
    {
        $event = Event::factory()->create(['capacity' => 2]);
        EventAttendee::factory()->approved()->create(['event_id' => $event->id]);
        EventAttendee::factory()->rejected()->count(5)->create(['event_id' => $event->id]);

        // One seat taken out of two: not full, and still open.
        $full = $this->getJson('/api/v1/events?status=full');
        $this->assertNull(collect($full->json('data'))->firstWhere('id', $event->id));

        $open = $this->getJson('/api/v1/events?status=open');
        $this->assertNotNull(collect($open->json('data'))->firstWhere('id', $event->id));
    }

    public function test_the_full_filter_counts_pending_registrations(): void
    {
        $event = Event::factory()->create(['capacity' => 2]);
        EventAttendee::factory()->approved()->create(['event_id' => $event->id]);
        EventAttendee::factory()->pending()->create(['event_id' => $event->id]);

        $full = $this->getJson('/api/v1/events?status=full');
        $this->assertNotNull(collect($full->json('data'))->firstWhere('id', $event->id));
    }

    public function test_a_rejected_member_may_register_again(): void
    {
        $event = Event::factory()->create();
        $user = $this->member();

        EventAttendee::factory()->rejected()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);

        Sanctum::actingAs($user, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
    }

    public function test_a_member_holding_a_seat_is_told_they_cannot_register_again(): void
    {
        $event = Event::factory()->create();
        $user = $this->member();

        EventAttendee::factory()->approved()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);

        Sanctum::actingAs($user, ['*']);
        $card = collect($this->getJson('/api/v1/events')->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertTrue($card['is_registered']);
        $this->assertFalse($card['can_register']);
        $this->assertSame('Registered', $card['registration_status']);
    }

    public function test_a_rejected_member_reads_as_not_registered_and_able_to_apply(): void
    {
        $event = Event::factory()->create();
        $user = $this->member();

        EventAttendee::factory()->rejected()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);

        Sanctum::actingAs($user, ['*']);
        $card = collect($this->getJson('/api/v1/events')->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertFalse($card['is_registered']);
        $this->assertTrue($card['can_register']);
        $this->assertNull($card['registration_status']);
    }

    public function test_my_events_shows_a_pending_registration_as_awaiting_review(): void
    {
        $event = Event::factory()->requiresApproval()->create();
        $user = $this->member();

        EventAttendee::factory()->pending()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);

        Sanctum::actingAs($user, ['*']);
        $response = $this->getJson('/api/v1/member/events/my-events');

        $card = collect($response->json('data'))->firstWhere('id', $event->id);
        $this->assertNotNull($card);
        $this->assertSame('registered', $card['registration']['status']);
        // No ticket for a registration nobody has admitted yet.
        $this->assertSame('view_event', $card['action']['type']);
    }

    public function test_my_events_surfaces_the_rejection_reason(): void
    {
        $event = Event::factory()->requiresApproval()->create();
        $user = $this->member();

        EventAttendee::factory()->rejected('Capacity reserved for partners')->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'phone' => $user->phone,
        ]);

        Sanctum::actingAs($user, ['*']);
        $card = collect($this->getJson('/api/v1/member/events/my-events')->json('data'))
            ->firstWhere('id', $event->id);

        $this->assertSame('rejected', $card['registration']['status']);
        $this->assertSame('Capacity reserved for partners', $card['registration']['rejection_reason']);
    }
}
