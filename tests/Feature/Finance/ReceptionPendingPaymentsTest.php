<?php

namespace Tests\Feature\Finance;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Events\PendingPaymentsChanged;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Notifications\GeneralNotification;
use App\Services\Notification\NotificationText;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The reception desk's list of everything owed across every member, and the
 * notice a member gets once the desk has taken their money.
 */
class ReceptionPendingPaymentsTest extends TestCase
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

    private function member(string $name, string $phone): User
    {
        $member = User::factory()->create(['name' => $name, 'phone' => $phone]);
        $member->assignRole('member');
        Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);

        return $member;
    }

    private function unpaidBooking(User $member, array $attributes = []): Booking
    {
        return Booking::factory()->create([
            'user_id' => $member->id,
            'amount_owed' => '10.00',
            'price_points' => 100,
            'payment_state' => PaymentState::Unpaid,
            'payment_source' => null,
            ...$attributes,
        ]);
    }

    public function test_it_lists_what_every_member_owes_with_the_member_and_matches_the_desk_count(): void
    {
        $sara = $this->member('Sara Haddad', '+963911111111');
        $omar = $this->member('Omar Naser', '+963922222222');

        // Chosen to pay at the desk, still to happen.
        $upcoming = $this->unpaidBooking($sara, ['start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour(), 'payment_method' => 'cash']);
        // Used already.
        $session = WalkinSession::factory()->create([
            'user_id' => $omar->id,
            'checked_in_at' => now()->subHours(3),
            'checked_out_at' => now()->subHours(2),
            'price_points' => 40,
            'payment_state' => PaymentState::Unpaid,
        ]);
        // Paid: not owed.
        $this->unpaidBooking($omar, ['payment_state' => PaymentState::Paid]);

        $response = $this->getJson('/api/v1/admin/reception/pending-payments')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(2, 'data');

        // What was used comes first; what is still to happen after it.
        $response->assertJsonPath('data.0.purpose', 'coworking_session')
            ->assertJsonPath('data.0.payable_id', $session->id)
            ->assertJsonPath('data.0.member.name', 'Omar Naser')
            ->assertJsonPath('data.0.amount_points', 40)
            ->assertJsonPath('data.1.purpose', 'booking')
            ->assertJsonPath('data.1.payable_id', $upcoming->id)
            ->assertJsonPath('data.1.member.id', $sara->id)
            ->assertJsonPath('data.1.member.phone', '+963911111111');

        $response->assertJsonStructure(['data' => [['amount' => ['value', 'currency']]]]);

        // The desk's card counts exactly these rows.
        $this->getJson('/api/v1/admin/reception/summary')->assertJsonPath('data.stats.pending_payments', 2);
    }

    public function test_search_narrows_to_a_member_by_name_or_phone(): void
    {
        $sara = $this->member('Sara Haddad', '+963911111111');
        $omar = $this->member('Omar Naser', '+963922222222');
        $this->unpaidBooking($sara);
        $this->unpaidBooking($omar);

        $this->getJson('/api/v1/admin/reception/pending-payments?search=Sara')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.member.id', $sara->id);

        $this->getJson('/api/v1/admin/reception/pending-payments?search=92222')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.member.id', $omar->id);
    }

    public function test_it_needs_the_desk_view_permission(): void
    {
        $member = $this->member('Sara Haddad', '+963911111111');
        Sanctum::actingAs($member, ['dashboard']);

        $this->getJson('/api/v1/admin/reception/pending-payments')->assertForbidden();
    }

    public function test_the_member_is_told_what_the_desk_took_and_the_change_left_in_their_wallet(): void
    {
        Notification::fake();
        ExchangeRate::factory()->create(['currency_code' => 'SYP', 'rate_to_base' => '0.0001']);
        $member = $this->member('Sara Haddad', '+963911111111');
        $booking = $this->unpaidBooking($member, ['checked_in_at' => now()->subHours(2), 'checked_out_at' => now()->subHour()]);

        // 150,000 SYP = 150 points, for a 100-point booking.
        $this->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash',
            'amount' => '150000', 'currency' => 'SYP',
        ])->assertCreated();

        $text = $this->notice($member, 'payment.received_at_reception_with_change');
        $this->assertSame(
            'Reception received 150000 SYP for a booking (100 points). Your payment is complete, and the remaining 50 points were added to your wallet.',
            $text['en']['body'],
        );
        $this->assertStringContainsString('150000 SYP', $text['ar']['body']);
        $this->assertStringContainsString('50 نقطة إلى محفظتك', $text['ar']['body']);
    }

    public function test_the_exact_amount_is_told_without_change(): void
    {
        Notification::fake();
        $member = $this->member('Sara Haddad', '+963911111111');
        $booking = $this->unpaidBooking($member, ['checked_in_at' => now()->subHours(2), 'checked_out_at' => now()->subHour()]);

        $this->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash',
        ])->assertCreated();

        $text = $this->notice($member, 'payment.received_at_reception');
        $this->assertSame('Reception received 10 USD for a booking (100 points). Your payment is complete.', $text['en']['body']);
    }

    public function test_the_desk_is_told_live_when_something_is_paid(): void
    {
        $member = $this->member('Sara Haddad', '+963911111111');
        $booking = $this->unpaidBooking($member, ['checked_in_at' => now()->subHours(2), 'checked_out_at' => now()->subHour()]);
        Event::fake([PendingPaymentsChanged::class]);

        $this->postJson('/api/v1/admin/reception/payments/settle', [
            'purpose' => 'booking', 'payable_id' => $booking->id, 'collected_via' => 'cash',
        ])->assertCreated();

        Event::assertDispatched(PendingPaymentsChanged::class, fn (PendingPaymentsChanged $e) => $e->memberId === $member->id
            && $e->broadcastAs() === 'payments.pending_changed');
    }

    public function test_the_desk_is_told_live_when_something_becomes_owed(): void
    {
        $member = $this->member('Sara Haddad', '+963911111111');
        $session = WalkinSession::factory()->create([
            'user_id' => $member->id,
            'checked_in_at' => now()->subHours(2),
            'checked_out_at' => null,
            'price_points' => 0,
            'payment_state' => PaymentState::Unpaid,
        ]);
        Event::fake([PendingPaymentsChanged::class]);

        // Closed unpaid: now the desk's to collect.
        $session->forceFill(['checked_out_at' => now(), 'price_points' => 40])->save();

        Event::assertDispatchedTimes(PendingPaymentsChanged::class, 1);
    }

    public function test_an_unrelated_change_tells_the_desk_nothing(): void
    {
        $member = $this->member('Sara Haddad', '+963911111111');
        $booking = $this->unpaidBooking($member);
        Event::fake([PendingPaymentsChanged::class]);

        $booking->forceFill(['note' => 'by the window'])->save();

        Event::assertNotDispatched(PendingPaymentsChanged::class);
    }

    /**
     * @return array{en: array{title: string, body: string}, ar: array{title: string, body: string}}
     */
    private function notice(User $member, string $template): array
    {
        $sent = Notification::sent($member, GeneralNotification::class)
            ->first(fn (GeneralNotification $n) => $n->toArray($member)['template_key'] === $template);

        $this->assertNotNull($sent, "No {$template} notification was sent.");
        $params = $sent->toArray($member)['params'];

        return [
            'en' => NotificationText::render($template, $params, 'en'),
            'ar' => NotificationText::render($template, $params, 'ar'),
        ];
    }
}
