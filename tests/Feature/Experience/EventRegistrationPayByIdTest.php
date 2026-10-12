<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Forms\Enums\FormPurpose;
use App\Domain\Forms\Models\FormField;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Pricing\Models\Coupon;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
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

    private function personalWallet(User $member): Wallet
    {
        return Wallet::where('owner_type', OwnerType::User)->where('owner_id', $member->id)->firstOrFail();
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

    /**
     * The seat records how it was actually paid: registered with cash,
     * paid by wallet — it reads `wallet`, not the first choice.
     */
    public function test_a_seat_paid_by_another_method_records_the_method_it_was_paid_with(): void
    {
        $member = $this->member(500);
        $event = $this->paidEvent(80);
        $ticket = $this->register($event->id, ['payment_method' => 'cash'])->json('data.ticket_id');
        $this->assertSame('cash', EventAttendee::findOrFail($ticket)->payment_method);

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])
            ->assertOk()
            ->assertJsonPath('data.next', 'done');

        $attendee = EventAttendee::findOrFail($ticket);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
        $this->assertSame('wallet', $attendee->payment_method);
        $this->assertSame(420, app(WalletService::class)->generalBalance($this->personalWallet($member)));
    }

    /**
     * Sent without a method, the ticket is paid from the member's own
     * wallet — as registering without one is — never a question of which
     * wallet when they also belong to a company that has points.
     */
    public function test_a_ticket_sent_without_a_method_is_paid_from_the_members_own_wallet(): void
    {
        $member = $this->member(500);
        $company = Company::factory()->create();
        $companyWallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);
        $company->members()->attach($member->id, ['is_admin' => false]);
        $event = $this->paidEvent(80);
        $ticket = $this->register($event->id, ['payment_method' => 'cash'])->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket])
            ->assertOk()
            ->assertJsonPath('data.next', 'done')
            ->assertJsonMissingPath('wallet_options');

        $this->assertSame(EventPaymentState::Paid, EventAttendee::findOrFail($ticket)->payment_state);
        $this->assertSame(420, app(WalletService::class)->generalBalance($this->personalWallet($member)));
        $this->assertSame(500, app(WalletService::class)->generalBalance($companyWallet->fresh()));
    }

    public function test_a_pending_ticket_sent_without_a_method_stores_the_members_own_wallet(): void
    {
        $member = $this->member(500);
        $event = $this->paidEvent(80, requiresApproval: true);
        $ticket = $this->register($event->id, ['payment_method' => 'cash'])->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket])
            ->assertOk()
            ->assertJsonPath('data.next', 'pay_at_reception');

        $attendee = EventAttendee::findOrFail($ticket);
        $this->assertSame('wallet', $attendee->payment_method);
        $this->assertSame('user', $attendee->payment_wallet_owner_type);
        $this->assertSame($member->id, $attendee->payment_wallet_owner_id);
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

    public function test_a_pending_registration_a_coupon_made_free_is_not_given_a_new_choice(): void
    {
        $this->member(500);
        Coupon::factory()->percentage(100)->forEvents()->create(['code' => 'GUEST']);
        $event = $this->paidEvent(80, requiresApproval: true);
        $ticket = $this->register($event->id, ['coupon_code' => 'GUEST'])->assertCreated()->json('data.ticket_id');

        $attendee = EventAttendee::findOrFail($ticket);
        $this->assertSame(EventRegistrationStatus::Pending, $attendee->status);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
        $this->assertSame('wallet', $attendee->payment_method);

        // Paid already: the stored choice is not overwritten, and nothing
        // answers "pay at the desk" for it. The existing fulfiller refuses a
        // seat that is not yet approved before it looks at what was paid.
        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'cash'])
            ->assertStatus(422)
            ->assertJsonPath('message', __('api.payment.registration_not_payable', [], 'en'));

        $this->assertSame('wallet', $attendee->refresh()->payment_method);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
    }

    public function test_an_approved_registration_that_is_already_paid_answers_already_paid(): void
    {
        $this->member(500);
        $event = $this->paidEvent(80);
        $ticket = $this->register($event->id, ['payment_method' => 'wallet'])->assertCreated()->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('api.payment.already_paid', [], 'en'));
    }

    public function test_a_pending_seat_of_a_free_event_is_not_given_a_payment_choice(): void
    {
        $this->member(500);
        $event = Event::factory()->requiresApproval()->create(['capacity' => 20]);
        $ticket = $this->register($event->id, [])->assertCreated()->json('data.ticket_id');

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'wallet'])->assertStatus(422);

        $attendee = EventAttendee::findOrFail($ticket);
        $this->assertNull($attendee->payment_method);
        $this->assertNull($attendee->payment_state);
    }
}
