<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormField;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * When a member may register: from `registration_opens_at` (or publication)
 * until `registration_closes_at` (or the event's start) — never once it has
 * begun — and not at all while an admin has marked it full.
 */
class EventRegistrationWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function member(): User
    {
        $user = User::factory()->create(['phone' => '0912345678']);
        $user->assignRole('member');
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /** @param  array<string, mixed>  $overrides */
    private function event(array $overrides = []): Event
    {
        return Event::create(array_merge([
            'title' => ['en' => 'Meetup', 'ar' => 'لقاء'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'location' => 'Main Hall',
            'capacity' => 50,
            'is_free' => true,
            'status' => 'published',
        ], $overrides));
    }

    public function test_an_event_that_has_started_cannot_be_registered_for_even_before_it_ends(): void
    {
        $this->member();
        $event = $this->event([
            'start_at' => now()->subHour(),
            'end_at' => now()->addDays(2),
        ]);

        $this->getJson("/api/v1/member/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.can_register', false)
            ->assertJsonPath('data.registration_state', 'closed');

        $this->postJson("/api/v1/member/events/{$event->id}/register")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');
    }

    public function test_registration_is_refused_before_it_opens(): void
    {
        $this->member();
        $event = $this->event(['registration_opens_at' => now()->addDay()]);

        $this->getJson("/api/v1/member/events/{$event->id}")
            ->assertJsonPath('data.can_register', false)
            ->assertJsonPath('data.registration_state', 'not_yet_open');

        $this->postJson("/api/v1/member/events/{$event->id}/register")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');
    }

    public function test_registration_is_refused_after_the_form_closes_even_though_the_event_is_ahead(): void
    {
        $this->member();
        $event = $this->event(['registration_closes_at' => now()->subMinute()]);

        $this->getJson("/api/v1/member/events/{$event->id}")
            ->assertJsonPath('data.can_register', false)
            ->assertJsonPath('data.registration_state', 'closed');

        $this->postJson("/api/v1/member/events/{$event->id}/register")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event');
    }

    public function test_registration_is_accepted_inside_the_window(): void
    {
        $this->member();
        $event = $this->event([
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
        ]);

        $this->getJson("/api/v1/member/events/{$event->id}")
            ->assertJsonPath('data.can_register', true)
            ->assertJsonPath('data.registration_state', 'open');

        $this->postJson("/api/v1/member/events/{$event->id}/register")->assertCreated();
    }

    public function test_an_event_marked_full_refuses_registration_despite_free_seats(): void
    {
        $this->member();
        $event = $this->event(['is_marked_full' => true]);

        $this->getJson("/api/v1/member/events/{$event->id}")
            ->assertJsonPath('data.can_register', false)
            ->assertJsonPath('data.status', 'full')
            ->assertJsonPath('data.registration_state', 'full');

        $this->postJson("/api/v1/member/events/{$event->id}/register")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('capacity');
    }

    public function test_an_external_event_marked_full_shows_full_and_hides_its_link(): void
    {
        $event = $this->event([
            'capacity' => null,
            'external_form_url' => 'https://forms.example.com/meetup',
            'is_marked_full' => true,
        ]);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'full')
            ->assertJsonPath('data.registration_state', 'full')
            ->assertJsonPath('data.external_form_url', null);

        // The link alone, withheld while full.
        $this->assertSame(
            ['external_form_url' => null],
            $this->getJson("/api/v1/events/{$event->id}/registration-form")->assertOk()->json('data'),
        );
    }

    public function test_an_open_external_event_still_hands_out_its_link(): void
    {
        $event = $this->event([
            'capacity' => null,
            'external_form_url' => 'https://forms.example.com/meetup',
        ]);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertJsonPath('data.registration_state', 'open')
            ->assertJsonPath('data.external_form_url', 'https://forms.example.com/meetup');
    }

    /**
     * can_register answers one question for every kind of event: may this
     * member register now — here, or on the external form. The window (open,
     * close, start) and "full" govern both kinds alike.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function canRegisterCases(): array
    {
        $external = ['capacity' => null, 'external_form_url' => 'https://forms.example.com/meetup'];

        return [
            'in-app, no form, open' => [[], true],
            'in-app, before it opens' => [['registration_opens_at' => '+1 day'], false],
            'in-app, form closed early' => [['registration_closes_at' => '-1 minute'], false],
            'in-app, started, not ended' => [['start_at' => '-1 hour', 'end_at' => '+2 days'], false],
            'in-app, marked full' => [['is_marked_full' => true], false],
            'in-app, draft' => [['status' => 'draft'], false],
            'external, open' => [$external, true],
            'external, before it opens' => [$external + ['registration_opens_at' => '+1 day'], false],
            'external, form closed early' => [$external + ['registration_closes_at' => '-1 minute'], false],
            'external, started, not ended' => [$external + ['start_at' => '-1 hour', 'end_at' => '+2 days'], false],
            'external, marked full' => [$external + ['is_marked_full' => true], false],
            'external, cancelled' => [$external + ['status' => 'cancelled'], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('canRegisterCases')]
    public function test_can_register_follows_the_window_full_and_form_kind(array $overrides, bool $expected): void
    {
        foreach (['start_at', 'end_at', 'registration_opens_at', 'registration_closes_at'] as $key) {
            if (isset($overrides[$key])) {
                $overrides[$key] = now()->modify($overrides[$key]);
            }
        }

        $this->member();
        $event = $this->event($overrides);

        $this->assertSame($expected, $this->getJson("/api/v1/member/events/{$event->id}")->json('data.can_register'));
    }

    public function test_an_in_app_event_with_questions_can_be_registered_while_open(): void
    {
        $this->member();
        $event = $this->event();
        FormField::factory()
            ->forOwner($event, FormPurpose::Registration)
            ->create(['key' => 'company']);

        $this->getJson("/api/v1/member/events/{$event->id}")
            ->assertJsonPath('data.can_register', true);
    }

    public function test_the_card_status_closes_once_the_event_starts_not_when_it_ends(): void
    {
        $event = $this->event(['start_at' => now()->subHour(), 'end_at' => now()->addDays(2)]);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_the_card_status_closes_when_the_form_closes_before_the_start(): void
    {
        $event = $this->event(['registration_closes_at' => now()->subMinute()]);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertJsonPath('data.status', 'closed');
    }

    public function test_an_event_whose_registration_has_not_opened_reads_upcoming(): void
    {
        $event = $this->event(['registration_opens_at' => now()->addDay()]);

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertJsonPath('data.status', 'upcoming')
            ->assertJsonPath('data.can_register', false);
    }

    public function test_the_upcoming_filter_lists_only_events_not_yet_open(): void
    {
        $upcoming = $this->event(['registration_opens_at' => now()->addDay()]);
        $open = $this->event(['registration_opens_at' => now()->subDay()]);
        $this->event(['registration_opens_at' => now()->addDay(), 'status' => 'cancelled']);

        $this->assertSame([$upcoming->id], array_column($this->getJson('/api/v1/events?status=upcoming')->json('data'), 'id'));
        $this->assertSame([$open->id], array_column($this->getJson('/api/v1/events?status=open')->json('data'), 'id'));
        $this->assertContains('upcoming', array_column($this->getJson('/api/v1/events/statuses')->json('data'), 'slug'));
    }

    public function test_the_status_filter_follows_the_registration_window(): void
    {
        $started = $this->event(['start_at' => now()->subHour(), 'end_at' => now()->addDays(2)]);
        $formClosed = $this->event(['registration_closes_at' => now()->subMinute()]);
        $open = $this->event(['registration_closes_at' => now()->addDay()]);

        $closedIds = array_column($this->getJson('/api/v1/events?status=closed')->json('data'), 'id');
        sort($closedIds);
        $this->assertSame([$started->id, $formClosed->id], $closedIds);
        $this->assertSame([$open->id], array_column($this->getJson('/api/v1/events?status=open')->json('data'), 'id'));
    }

    public function test_the_status_filter_counts_a_marked_full_event_as_full_not_open(): void
    {
        $full = $this->event(['is_marked_full' => true]);
        $open = $this->event();

        $this->assertSame([$full->id], array_column($this->getJson('/api/v1/events?status=full')->json('data'), 'id'));
        $this->assertSame([$open->id], array_column($this->getJson('/api/v1/events?status=open')->json('data'), 'id'));
    }

    public function test_admin_can_set_the_window_and_mark_full(): void
    {
        $this->admin();
        $event = $this->event();

        $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'registration_opens_at' => now()->addHour()->format('Y-m-d H:i'),
            'registration_closes_at' => now()->addDay()->format('Y-m-d H:i'),
            'is_marked_full' => true,
        ])->assertOk();

        $event->refresh();
        $this->assertNotNull($event->registration_opens_at);
        $this->assertNotNull($event->registration_closes_at);
        $this->assertTrue($event->is_marked_full);

        $this->getJson("/api/v1/admin/events/{$event->id}")
            ->assertJsonPath('data.is_marked_full', true)
            ->assertJsonPath('data.registration_closes_at', $event->registration_closes_at->toISOString());
    }

    public function test_the_form_cannot_close_after_the_event_starts(): void
    {
        $this->admin();
        $event = $this->event();

        $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'registration_closes_at' => now()->addDays(3)->format('Y-m-d H:i'),
        ])->assertUnprocessable()->assertJsonValidationErrors('registration_closes_at');
    }

    public function test_the_form_cannot_close_before_it_opens(): void
    {
        $this->admin();
        $event = $this->event();

        $this->patchJson("/api/v1/admin/events/{$event->id}", [
            'registration_opens_at' => now()->addDay()->format('Y-m-d H:i'),
            'registration_closes_at' => now()->addHours(2)->format('Y-m-d H:i'),
        ])->assertUnprocessable()->assertJsonValidationErrors('registration_closes_at');
    }

    public function test_the_close_is_checked_against_the_new_schedule_when_sessions_move(): void
    {
        $this->admin();

        $this->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Workshop', 'ar' => 'ورشة'],
            'location' => 'Hall',
            'sessions' => [
                ['start_at' => now()->addDays(5)->format('Y-m-d H:i'), 'end_at' => now()->addDays(5)->addHours(2)->format('Y-m-d H:i')],
                ['start_at' => now()->addDays(3)->format('Y-m-d H:i'), 'end_at' => now()->addDays(3)->addHours(2)->format('Y-m-d H:i')],
            ],
            // After the first sitting (day 3), though before the second.
            'registration_closes_at' => now()->addDays(4)->format('Y-m-d H:i'),
        ])->assertUnprocessable()->assertJsonValidationErrors('registration_closes_at');
    }
}
