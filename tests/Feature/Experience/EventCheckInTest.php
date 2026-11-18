<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Checking an attendee in at the door
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 8): a paid
 * event's registration that is still unpaid does not get in.
 */
class EventCheckInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
    }

    private function event(bool $free = false): Event
    {
        return Event::create([
            'title' => ['en' => 'Talk', 'ar' => 'محاضرة'],
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
            'location' => 'Hall',
            'is_free' => $free,
            'price_points' => $free ? 0 : 100,
            'status' => 'published',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function attendee(Event $event, array $attributes = []): EventAttendee
    {
        return EventAttendee::create(array_merge([
            'event_id' => $event->id,
            'user_id' => User::factory()->create()->id,
            'name' => 'Guest',
            'email' => fake()->unique()->safeEmail(),
            'status' => EventRegistrationStatus::Approved,
        ], $attributes));
    }

    private function checkIn(Event $event, EventAttendee $attendee)
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/admin/events/{$event->id}/attendees/{$attendee->id}/check-in");
    }

    public function test_an_unpaid_registration_for_a_paid_event_is_turned_away(): void
    {
        $event = $this->event();
        $attendee = $this->attendee($event, ['price_points' => 100, 'payment_state' => EventPaymentState::Unpaid]);

        $this->checkIn($event, $attendee)
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.event.check_in_unpaid', [], 'en'));

        $this->assertNull($attendee->refresh()->checked_in_at);
    }

    public function test_a_paid_registration_is_checked_in(): void
    {
        $event = $this->event();
        $attendee = $this->attendee($event, ['price_points' => 100, 'payment_state' => EventPaymentState::Paid, 'paid_at' => now()]);

        $this->checkIn($event, $attendee)
            ->assertOk()
            ->assertJsonPath('message', __('api.event.checked_in', [], 'en'));

        $attendee->refresh();
        $this->assertTrue($attendee->attended);
        $this->assertNotNull($attendee->checked_in_at);
        $this->assertNotNull($attendee->checked_in_by);
        $this->assertSame(1, Activity::where('description', 'event_attendee_checked_in')->count());
    }

    public function test_a_free_event_needs_no_payment(): void
    {
        $event = $this->event(free: true);

        $this->checkIn($event, $this->attendee($event))->assertOk();
    }

    public function test_a_registration_not_approved_is_turned_away(): void
    {
        $event = $this->event(free: true);
        $attendee = $this->attendee($event, ['status' => EventRegistrationStatus::Pending]);

        $this->checkIn($event, $attendee)
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.event.check_in_not_approved', [], 'en'));
    }

    public function test_checking_in_twice_is_refused(): void
    {
        $event = $this->event(free: true);
        $attendee = $this->attendee($event);

        $this->checkIn($event, $attendee)->assertOk();
        $this->checkIn($event, $attendee)
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.event.already_checked_in', [], 'en'));
    }

    public function test_another_events_attendee_is_not_found(): void
    {
        $event = $this->event(free: true);
        $other = $this->attendee($this->event(free: true));

        $this->checkIn($event, $other)->assertNotFound();
    }
}
