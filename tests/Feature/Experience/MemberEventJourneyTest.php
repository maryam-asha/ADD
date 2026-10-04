<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventSession;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormField;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Support\Forms\FieldType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What the member app needs from an event, end to end.
 *
 * Browse with filters, open one, learn whether it asks questions or takes the
 * profile as-is, see where an application stands, fetch the form, submit it.
 *
 * The endpoints existed; this covers the journey as one thing and closes the
 * gaps it exposed — chiefly that a turned-down member was shown no trace of
 * their own application.
 */
class MemberEventJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function member(): User
    {
        $user = User::factory()->create([
            'name' => 'Real Member',
            'email' => 'member@example.com',
            'phone' => '0912345678',
        ]);
        $user->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $user->id]);

        return $user;
    }

    private function published(array $overrides = []): Event
    {
        return Event::factory()->create(array_merge([
            'status' => 'published',
            'start_at' => now()->addWeek(),
            'end_at' => now()->addWeek()->addHours(2),
        ], $overrides));
    }

    // ── Browsing ─────────────────────────────────────────────────────────

    public function test_a_member_browses_events_and_can_narrow_them_down(): void
    {
        $free = $this->published(['is_free' => true, 'title' => ['en' => 'Open day', 'ar' => 'يوم مفتوح']]);
        $paid = $this->published(['is_free' => false, 'price_points' => 50]);

        Sanctum::actingAs($this->member(), ['*']);

        $all = $this->getJson('/api/v1/member/events')->assertStatus(200)->json('data');
        $this->assertGreaterThanOrEqual(2, count($all));

        $freeOnly = collect($this->getJson('/api/v1/member/events?price_type=free')
            ->assertStatus(200)->json('data'))->pluck('id');

        $this->assertTrue($freeOnly->contains($free->id));
        $this->assertFalse($freeOnly->contains($paid->id));
    }

    public function test_browsing_can_be_searched_and_paged(): void
    {
        $this->published(['title' => ['en' => 'Robotics night', 'ar' => 'ليلة الروبوتات']]);
        $this->published(['title' => ['en' => 'Poetry night', 'ar' => 'ليلة الشعر']]);

        Sanctum::actingAs($this->member(), ['*']);

        $found = $this->getJson('/api/v1/member/events?search=Robotics&per_page=1')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $found);
        $this->assertStringContainsString('Robotics', $found[0]['title']);
    }

    // ── Details: does this event ask anything? ───────────────────────────

    /**
     * Most events need nothing the account does not already hold, and the app
     * has to be able to tell those apart without fetching the form first.
     */
    public function test_details_say_whether_the_event_asks_its_own_questions(): void
    {
        $plain = $this->published();
        $asking = $this->published(['has_registration_form' => true]);
        FormField::factory()->forOwner($asking, FormPurpose::Registration)->create([
            'key' => 'dietary_needs',
            'type' => FieldType::Text,
        ]);

        Sanctum::actingAs($this->member(), ['*']);

        $this->assertFalse(
            $this->getJson("/api/v1/member/events/{$plain->id}")->json('data.has_registration_form')
        );
        $this->assertTrue(
            $this->getJson("/api/v1/member/events/{$asking->id}")->json('data.has_registration_form')
        );
    }

    public function test_details_say_whether_registration_happens_elsewhere(): void
    {
        $external = $this->published(['external_form_url' => 'https://forms.example.com/abc']);

        Sanctum::actingAs($this->member(), ['*']);

        $payload = $this->getJson("/api/v1/member/events/{$external->id}")->json('data');

        $this->assertSame('https://forms.example.com/abc', $payload['external_form_url']);
        // Registration is open — it just happens on the external form.
        $this->assertTrue($payload['can_register']);
    }

    public function test_details_carry_the_schedule_when_the_event_runs_several_days(): void
    {
        $event = $this->published();
        EventSession::factory()->for($event)->on('2027-03-01', '09:00', '17:00')->create();
        EventSession::factory()->for($event)->on('2027-03-02', '16:00', '21:00')->create();

        Sanctum::actingAs($this->member(), ['*']);

        $schedule = $this->getJson("/api/v1/member/events/{$event->id}")->json('data.schedule');

        $this->assertCount(2, $schedule);
        $this->assertSame('09:00 AM', $schedule[0]['start_time']);
        $this->assertSame('04:00 PM', $schedule[1]['start_time']);
    }

    // ── Details: where does my application stand? ────────────────────────

    public function test_an_applicant_awaiting_a_decision_is_told_so(): void
    {
        $event = $this->published(['requires_approval' => true]);
        $member = $this->member();

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertStatus(201);

        $payload = $this->getJson("/api/v1/member/events/{$event->id}")->json('data');

        $this->assertTrue($payload['is_registered']);
        $this->assertSame('Registered', $payload['registration_status']);
        $this->assertFalse($payload['can_register']);

        $payloadAr = $this->withHeaders(['lang' => 'ar'])->getJson("/api/v1/member/events/{$event->id}")->json('data');
        $this->assertSame('مسجّل', $payloadAr['registration_status']);
    }

    public function test_an_accepted_member_sees_that_they_are_in(): void
    {
        $event = $this->published();
        $member = $this->member();

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertStatus(201);

        $this->assertSame(
            'Registered',
            $this->getJson("/api/v1/member/events/{$event->id}")->json('data.registration_status')
        );
    }

    /**
     * The gap this test was written for: the lookup was scoped to
     * seat-holding rows, so a turned-down member saw no trace of their own
     * application — no status, no reason, and an invitation to apply again
     * with no hint of what had happened.
     */
    public function test_a_turned_down_member_is_told_so_and_why(): void
    {
        $event = $this->published(['requires_approval' => true]);
        $member = $this->member();

        EventAttendee::factory()->for($event)->create([
            'user_id' => $member->id,
            'email' => $member->email,
            'status' => 'rejected',
            'rejection_reason' => 'The session is full.',
        ]);

        Sanctum::actingAs($member, ['*']);
        $payload = $this->getJson("/api/v1/member/events/{$event->id}")->json('data');

        $this->assertSame('Rejected', $payload['registration_status']);
        $this->assertSame('The session is full.', $payload['rejection_reason']);

        $payloadAr = $this->withHeaders(['lang' => 'ar'])->getJson("/api/v1/member/events/{$event->id}")->json('data');
        $this->assertSame('مرفوض', $payloadAr['registration_status']);
    }

    /** A rejected application does not hold a seat, so re-applying is allowed. */
    public function test_a_turned_down_member_may_still_apply_again(): void
    {
        $event = $this->published();
        $member = $this->member();

        EventAttendee::factory()->for($event)->create([
            'user_id' => $member->id,
            'email' => $member->email,
            'status' => 'rejected',
            'rejection_reason' => 'Try the later session.',
        ]);

        Sanctum::actingAs($member, ['*']);

        $this->assertTrue($this->getJson("/api/v1/member/events/{$event->id}")->json('data.can_register'));
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertStatus(201);
    }

    // ── The form ─────────────────────────────────────────────────────────

    public function test_the_form_carries_the_questions_and_the_members_own_details(): void
    {
        $event = $this->published(['has_registration_form' => true]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'dietary_needs',
            'label' => ['en' => 'Dietary needs', 'ar' => 'الاحتياجات الغذائية'],
            'type' => FieldType::Text,
            'is_required' => true,
        ]);

        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        $form = $this->getJson("/api/v1/member/events/{$event->id}/registration-form")
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $form['registration_form']);
        $this->assertSame('dietary_needs', $form['registration_form'][0]['key']);
        $this->assertArrayNotHasKey('identity', $form);
    }

    /** An event that asks nothing still answers 200, with no form to fill. */
    public function test_an_event_that_asks_nothing_returns_no_form(): void
    {
        $event = $this->published();

        Sanctum::actingAs($this->member(), ['*']);

        $form = $this->getJson("/api/v1/member/events/{$event->id}/registration-form")
            ->assertStatus(200)
            ->json('data');

        $this->assertArrayNotHasKey('registration_form', $form);
        $this->assertArrayNotHasKey('has_registration_form', $form);
    }

    // ── Submitting ───────────────────────────────────────────────────────

    public function test_a_member_registers_by_answering_the_form(): void
    {
        $event = $this->published(['has_registration_form' => true]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'dietary_needs',
            'type' => FieldType::Text,
            'is_required' => true,
        ]);

        $member = $this->member();
        Sanctum::actingAs($member, ['*']);

        $this->postJson("/api/v1/member/events/{$event->id}/register", [
            'fields' => ['dietary_needs' => 'No nuts'],
        ])->assertStatus(201)->assertJsonStructure(['data' => ['status', 'requires_approval']]);

        $this->assertDatabaseHas('event_attendees', [
            'event_id' => $event->id,
            'user_id' => $member->id,
        ]);
    }

    /** An event needing nothing extra registers on the profile alone. */
    public function test_an_event_that_asks_nothing_registers_on_the_profile_alone(): void
    {
        $event = $this->published();
        $member = $this->member();

        Sanctum::actingAs($member, ['*']);

        $this->postJson("/api/v1/member/events/{$event->id}/register")
            ->assertStatus(201);

        $this->assertDatabaseHas('event_attendees', [
            'event_id' => $event->id,
            'user_id' => $member->id,
            'name' => $member->name,
        ]);
    }

    public function test_a_missing_required_answer_is_refused(): void
    {
        $event = $this->published(['has_registration_form' => true]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->create([
            'key' => 'dietary_needs',
            'type' => FieldType::Text,
            'is_required' => true,
        ]);

        Sanctum::actingAs($this->member(), ['*']);

        $this->postJson("/api/v1/member/events/{$event->id}/register", ['fields' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fields.dietary_needs');
    }

    public function test_registering_twice_is_refused(): void
    {
        $event = $this->published();
        $member = $this->member();

        Sanctum::actingAs($member, ['*']);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertStatus(201);
        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertStatus(422);
    }
}
