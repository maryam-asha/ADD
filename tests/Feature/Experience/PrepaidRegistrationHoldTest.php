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
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A seat confirmed on the spot and paid by card is held until the card goes
 * through or another method is chosen (docs/decisions/prepayment-all-or-nothing.md).
 */
class PrepaidRegistrationHoldTest extends TestCase
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

    private function register(int $eventId, array $body)
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/events/{$eventId}/register", $body);
    }

    public function test_a_card_registration_is_held_and_a_ticket_switch_to_cash_releases_it(): void
    {
        $this->member();
        $event = $this->paidEvent(80);
        Http::fake(['*' => Http::response('', 500)]);

        $ticket = $this->register($event->id, ['payment_method' => 'paymera'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->json('data.ticket_id');

        $this->assertNotNull(EventAttendee::findOrFail($ticket)->payment_due_at);

        $this->register($event->id, ['ticket_id' => $ticket, 'payment_method' => 'cash'])->assertOk();

        $attendee = EventAttendee::findOrFail($ticket);
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertNull($attendee->payment_due_at);
    }

    public function test_a_registration_needing_approval_is_not_held(): void
    {
        $this->member();
        $event = $this->paidEvent(80, requiresApproval: true);

        $this->register($event->id, ['payment_method' => 'paymera'])
            ->assertJsonPath('data.status', 'pending');
    }
}
