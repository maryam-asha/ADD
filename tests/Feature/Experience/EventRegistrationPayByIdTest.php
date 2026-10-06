<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormField;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Support\Enums\EventPaymentState;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An event registration is made first, then paid: to pay it another way the
 * app sends the same request again with the ticket the first answer
 * returned. Nothing is registered and no registration rule runs
 * (docs/superpowers/specs/2026-10-06-prepayment-all-or-nothing-design.md).
 */
class EventRegistrationPayByIdTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL = '99990001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        config([
            'paymera.base_url' => 'https://egate-t.paymera.test',
            'paymera.api_key' => 'test-api-key',
            'paymera.terminal_id' => self::TERMINAL,
            'paymera.currency' => 'USD',
            'paymera.currency_exponent' => 2,
            'paymera.default_lang' => 'en',
        ]);
    }

    private function member(int $points = 0): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        if ($points > 0) {
            app(WalletService::class)->creditGeneral($wallet, $points, WalletTransactionSource::TopUp);
        }

        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function paidEvent(int $points, bool $requiresApproval = false): Event
    {
        return Event::factory()->paid($points)->create([
            'capacity' => 20,
            'requires_approval' => $requiresApproval,
        ]);
    }

    private function paidEventWithRequiredForm(int $points): Event
    {
        $event = Event::factory()->paid($points)->withForm()->create(['capacity' => 20]);
        FormField::factory()->forOwner($event, FormPurpose::Registration)->required()->create(['key' => 'company']);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function validFormAnswers(Event $event): array
    {
        return ['fields' => ['company' => 'ACME']];
    }

    private function register(int $eventId, array $body)
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/events/{$eventId}/register", $body);
    }

    public function test_an_approved_unpaid_registration_is_paid_by_sending_the_request_again_with_its_ticket(): void
    {
        $this->member(500);
        $event = $this->paidEvent(80); // approved on the spot, 80 points
        $ticket = $this->register($event->id, ['payment_method' => 'cash'])->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('data.ticket_id', $ticket)
            ->assertJsonPath('data.next', 'done');

        $this->assertSame(EventPaymentState::Paid, EventAttendee::findOrFail($ticket)->payment_state);
        $this->assertSame(1, EventAttendee::count());
    }

    public function test_the_form_is_not_asked_for_again(): void
    {
        $this->member(500);
        $event = $this->paidEventWithRequiredForm(80);
        $ticket = $this->register($event->id, ['payment_method' => 'cash'] + $this->validFormAnswers($event))->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])->assertOk();
    }

    public function test_a_pending_registration_has_its_stored_choice_updated_and_nothing_charged(): void
    {
        $this->member(500);
        $event = $this->paidEvent(80, requiresApproval: true);
        $ticket = $this->register($event->id, ['payment_method' => 'cash'])->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('data.next', 'pay_at_reception');

        $attendee = EventAttendee::findOrFail($ticket);
        $this->assertSame('wallet', $attendee->payment_method);
        $this->assertSame(EventPaymentState::Unpaid, $attendee->payment_state);
    }

    public function test_another_members_ticket_is_not_found(): void
    {
        $this->member(500);
        $event = $this->paidEvent(80);
        $someoneElse = EventAttendee::factory()->create(['event_id' => $event->id]);

        $this->register($event->id, ['ticket_id' => $someoneElse->id, 'payment_method' => 'wallet'])->assertNotFound();
    }

    public function test_without_a_ticket_registering_again_is_still_refused(): void
    {
        $this->member(500);
        $event = $this->paidEvent(80);
        $this->register($event->id, ['payment_method' => 'cash'])->assertCreated();

        $this->register($event->id, ['payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('registration');
    }
}
