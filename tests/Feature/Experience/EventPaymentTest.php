<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionSource;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Services\Experience\EventApprovalService;
use App\Services\Experience\Exceptions\EventActionException;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Paying for an event registration by any method
 * (docs/superpowers/plans/2026-09-30-unified-payments.md, Task 8): points
 * now, cash at the desk, or a card — confirmed unpaid until it is paid.
 */
class EventPaymentTest extends TestCase
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

    private function balance(OwnerType $type, int $id): int
    {
        return app(WalletService::class)->generalBalance(
            Wallet::where('owner_type', $type)->where('owner_id', $id)->firstOrFail()
        );
    }

    private function paidEvent(bool $requiresApproval = false): Event
    {
        return Event::create([
            'title' => ['en' => 'Masterclass', 'ar' => 'دورة'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'location' => 'Main Hall',
            'capacity' => 20,
            'is_free' => false,
            'price_points' => 100,
            'status' => 'published',
            'requires_approval' => $requiresApproval,
        ]);
    }

    private function register(Event $event, array $body = [])
    {
        return $this->withHeader('lang', 'en')->postJson("/api/v1/member/events/{$event->id}/register", $body);
    }

    public function test_paying_by_wallet_pays_now_and_records_the_payment(): void
    {
        $member = $this->member(300);
        $event = $this->paidEvent();

        $this->register($event, ['payment_method' => 'wallet'])
            ->assertCreated()
            ->assertJsonMissingPath('data.payment');

        $attendee = EventAttendee::sole();
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
        $this->assertSame(100, $attendee->price_points);
        $this->assertNotNull($attendee->paid_at);

        $payment = Payment::sole();
        $this->assertSame(PaymentPurpose::EventRegistration, $payment->purpose);
        $this->assertSame('event_attendee', $payment->payable_type);
        $this->assertSame($attendee->id, $payment->payable_id);
        $this->assertSame(200, $this->balance(OwnerType::User, $member->id));

        $activity = Activity::where('description', 'event_registered')->sole();
        $this->assertSame(100, $activity->properties['price_points']);
        $this->assertSame('wallet', $activity->properties['payment_method']);
    }

    public function test_no_method_still_means_the_wallet(): void
    {
        $member = $this->member(300);

        $this->register($this->paidEvent())->assertCreated();

        $this->assertSame(EventPaymentState::Paid, EventAttendee::sole()->payment_state);
        $this->assertSame(200, $this->balance(OwnerType::User, $member->id));
    }

    public function test_a_company_wallet_can_pay(): void
    {
        $member = $this->member(0);
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);

        $this->register($this->paidEvent(), [
            'payment_method' => 'wallet',
            'wallet_owner_type' => 'company',
            'wallet_owner_id' => $company->id,
        ])->assertCreated();

        $this->assertSame(400, $this->balance(OwnerType::Company, $company->id));
        $this->assertSame($companyWallet->id, Payment::sole()->wallet_id);
    }

    public function test_too_few_points_keep_the_old_validation_error(): void
    {
        $this->member(30);

        $this->register($this->paidEvent(), ['payment_method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('points')
            ->assertJsonPath('errors.points.0', __('api.event.insufficient_points', ['current' => 30, 'required' => 100, 'shortage' => 70], 'en'));

        $this->assertSame(0, EventAttendee::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_cash_confirms_the_seat_unpaid(): void
    {
        $member = $this->member(300);

        $this->register($this->paidEvent(), ['payment_method' => 'cash'])->assertCreated();

        $attendee = EventAttendee::sole();
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertSame(EventPaymentState::Unpaid, $attendee->payment_state);
        $this->assertSame('cash', $attendee->payment_method);
        $this->assertSame(0, Payment::count());
        $this->assertSame(300, $this->balance(OwnerType::User, $member->id));
    }

    public function test_a_card_confirms_the_seat_and_is_paid_once_the_gateway_accepts(): void
    {
        Http::fake([
            '*/api/create-payment' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => ['url' => 'https://egate-t.paymera.test/start/pay-5/en', 'paymentId' => 'pay-5'],
            ]),
            '*/api/get-payment-status/*' => Http::response([
                'ErrorMessage' => 'Success',
                'ErrorCode' => 0,
                'Data' => [
                    'status' => 'A',
                    'creationTimestamp' => '2026-08-17 08:00:00.000',
                    'rrn' => '000009876543',
                    'amount' => 1000,
                    'terminalId' => self::TERMINAL,
                ],
            ]),
        ]);
        $this->member(0);

        $this->register($this->paidEvent(), ['payment_method' => 'paymera'])
            ->assertCreated()
            ->assertJsonPath('data.redirect_url', 'https://egate-t.paymera.test/start/pay-5/en')
            ->assertJsonPath('data.payment.method', 'paymera');

        $attendee = EventAttendee::sole();
        $this->assertSame(EventPaymentState::Unpaid, $attendee->payment_state);

        app(SyncPaymentStatus::class)->handle(Payment::sole());

        $this->assertSame(EventPaymentState::Paid, $attendee->refresh()->payment_state);
    }

    public function test_approval_charges_the_wallet_the_member_chose(): void
    {
        $member = $this->member(0);
        $company = Company::factory()->create();
        $member->companies()->attach($company->id);
        $companyWallet = Wallet::create(['owner_type' => OwnerType::Company, 'owner_id' => $company->id]);
        app(WalletService::class)->creditGeneral($companyWallet, 500, WalletTransactionSource::TopUp);
        $event = $this->paidEvent(requiresApproval: true);

        $this->register($event, [
            'payment_method' => 'wallet',
            'wallet_owner_type' => 'company',
            'wallet_owner_id' => $company->id,
        ])->assertCreated();

        $attendee = EventAttendee::sole();
        $this->assertSame(EventRegistrationStatus::Pending, $attendee->status);
        $this->assertNull($attendee->payment_state === EventPaymentState::Paid ? 'paid' : null);
        $this->assertSame(0, Payment::count());

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        app(EventApprovalService::class)->approve($attendee, $operator);

        $attendee->refresh();
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertSame(EventPaymentState::Paid, $attendee->payment_state);
        $this->assertSame(400, $this->balance(OwnerType::Company, $company->id));
    }

    public function test_approval_refuses_when_the_wallet_falls_short(): void
    {
        $this->member(30);
        $event = $this->paidEvent(requiresApproval: true);
        $this->register($event, ['payment_method' => 'wallet'])->assertCreated();
        $attendee = EventAttendee::sole();

        $operator = User::factory()->create();
        $operator->assignRole('operations');

        try {
            app(EventApprovalService::class)->approve($attendee, $operator);
            $this->fail('Approved without the points.');
        } catch (EventActionException $e) {
            $this->assertSame('api.event.approve_insufficient_points', $e->messageKey);
        }

        $this->assertSame(EventRegistrationStatus::Pending, $attendee->refresh()->status);
    }

    public function test_approval_with_cash_confirms_the_seat_unpaid(): void
    {
        $this->member(0);
        $event = $this->paidEvent(requiresApproval: true);
        $this->register($event, ['payment_method' => 'cash'])->assertCreated();
        $attendee = EventAttendee::sole();

        $operator = User::factory()->create();
        $operator->assignRole('operations');
        app(EventApprovalService::class)->approve($attendee, $operator);

        $attendee->refresh();
        $this->assertSame(EventRegistrationStatus::Approved, $attendee->status);
        $this->assertSame(EventPaymentState::Unpaid, $attendee->payment_state);
    }

    public function test_an_unpaid_registration_can_be_paid_later_from_the_app(): void
    {
        $member = $this->member(300);
        $this->register($this->paidEvent(), ['payment_method' => 'cash'])->assertCreated();
        $attendee = EventAttendee::sole();

        $this->withHeader('lang', 'en')->postJson('/api/v1/member/payments', [
            'purpose' => 'event_registration',
            'method' => 'wallet',
            'payable_id' => $attendee->id,
        ])->assertCreated()->assertJsonPath('data.next', 'done');

        $this->assertSame(EventPaymentState::Paid, $attendee->refresh()->payment_state);
        $this->assertSame(200, $this->balance(OwnerType::User, $member->id));
    }

    public function test_a_free_event_has_no_payment_state(): void
    {
        $this->member(0);
        $event = Event::create([
            'title' => ['en' => 'Meetup', 'ar' => 'لقاء'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHour(),
            'location' => 'Hall',
            'is_free' => true,
            'price_points' => 0,
            'status' => 'published',
        ]);

        $this->register($event)->assertCreated();

        $this->assertNull(EventAttendee::sole()->payment_state);
    }

    public function test_the_members_event_detail_shows_the_payment(): void
    {
        $this->member(0);
        $event = $this->paidEvent();
        $this->register($event, ['payment_method' => 'cash'])->assertCreated();

        $this->withHeader('lang', 'en')->getJson("/api/v1/member/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.state', 'unpaid')
            ->assertJsonPath('data.payment.method', 'cash')
            ->assertJsonPath('data.payment.amount_points', 100);
    }
}
