<?php

namespace Tests\Feature\Notification;

use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\SyncPaymentStatus;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Providers\Enums\Payer;
use App\Domain\Providers\Models\Provider;
use App\Domain\Renewals\Services\RenewalRunner;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use App\Services\Notification\ReminderRunner;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\Feature\Renewals\RenewalsTestCase;

/**
 * The money and operations matters that need a person: a payment to check, a
 * renewal that stopped, a rate to approve, invoices gone overdue, a session
 * closed without its member. Each reaches whoever may act on it.
 */
class StaffOperationsNoticesTest extends RenewalsTestCase
{
    use ReadsNotices;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->staff = User::factory()->create();
        $this->staff->assignRole('admin');
    }

    public function test_a_payment_that_fails_verification_is_flagged_for_review(): void
    {
        config(['paymera.base_url' => 'https://egate-t.paymera.test', 'paymera.api_key' => 'k', 'paymera.terminal_id' => '99990001', 'paymera.currency' => 'USD']);
        Http::fake(['*/api/get-payment-status/*' => Http::response([
            'ErrorMessage' => 'Success', 'ErrorCode' => 0,
            'Data' => ['status' => 'A', 'creationTimestamp' => '2026-01-26 12:06:52.547', 'rrn' => '1', 'amount' => 999, 'terminalId' => '99990001'],
        ])]);
        $member = User::factory()->create();
        $wallet = Wallet::create(['owner_type' => OwnerType::User, 'owner_id' => $member->id]);
        $payment = Payment::factory()->create(['payment_id' => 'pay-1', 'user_id' => $member->id, 'wallet_id' => $wallet->id, 'charged_amount' => 1000, 'points_to_credit' => 100, 'terminal_id' => '99990001']);

        app(SyncPaymentStatus::class)->handle($payment);

        $notice = $this->assertNoticed($this->staff, 'payment.needs_review', NotificationAudience::Staff);
        $this->assertSame((string) $payment->fresh()->reference, $notice->data['params']['id']);
        $this->assertSame(100, $notice->data['params']['points']);
    }

    public function test_a_stopped_renewal_reaches_staff_and_the_provider_once(): void
    {
        $provider = Provider::factory()->create();
        $account = User::factory()->create();
        $account->assignRole('provider');
        $provider->users()->attach($account->id);
        $current = $this->assignedMembership($provider);
        $current->update(['auto_renew' => true]);
        Invoice::query()->get()->each(fn (Invoice $invoice) => $invoice->update(['status' => InvoiceStatus::Paid, 'paid_amount' => $invoice->amount]));
        Invoice::factory()->create(['provider_id' => $provider->id, 'billed_to' => Payer::Provider, 'due_at' => now()->subDay()->toDateString()]);
        $this->travelTo($current->current_period_end->copy()->subDays(5));

        app(RenewalRunner::class)->run();
        $this->travel(1)->hours();
        app(RenewalRunner::class)->run();

        $notice = $this->assertNoticed($this->staff, 'renewal.stopped', NotificationAudience::Staff);
        $this->assertStringContainsString('overdue invoice', $this->noticeText($notice, 'en')['body']);
        $this->assertNoticed($account, 'renewal.stopped', NotificationAudience::Provider);
    }

    public function test_a_new_rate_suggestion_asks_for_approval(): void
    {
        Http::preventStrayRequests();
        Http::fake(['api-v2.sp-today.com/*' => Http::response(['ok' => true, 'data' => ['unit' => 'new', 'currencies' => [
            ['code' => 'USD', 'cities' => ['damascus' => ['buy' => 132.25, 'sell' => 132.75]]],
        ]]], 200)]);

        $this->artisan('finance:fetch-exchange-rate-suggestion')->assertExitCode(0);

        $notice = $this->assertNoticed($this->staff, 'exchange_rate.suggested', NotificationAudience::Staff);
        $this->assertSame('132.75', (string) $notice->data['params']['rate']);
    }

    public function test_overdue_invoices_are_summed_up_once_a_day_from_nine(): void
    {
        Invoice::factory()->count(2)->create(['due_at' => now()->subDays(3)->toDateString()]);

        $this->travelTo(Carbon::parse('tomorrow 08:00', 'Asia/Damascus'));
        app(ReminderRunner::class)->run();
        $this->assertNotNoticed($this->staff, 'invoice.overdue_digest');

        $this->travelTo(Carbon::parse('today 09:05', 'Asia/Damascus'));
        app(ReminderRunner::class)->run();
        app(ReminderRunner::class)->run();

        $this->assertSame(2, $this->assertNoticed($this->staff, 'invoice.overdue_digest', NotificationAudience::Staff)->data['params']['count']);
    }

    public function test_a_session_closed_at_closing_time_is_flagged_to_the_desk(): void
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100, 'name' => ['ar' => 'القاعة', 'en' => 'Hall']]);
        BusinessHour::factory()->create(['branch_id' => $space->building->branch_id, 'day_of_week' => DayOfWeek::Monday, 'open_time' => '08:00', 'close_time' => '20:00']);
        $session = WalkinSession::factory()->create(['space_id' => $space->id, 'checked_in_at' => Carbon::parse('2026-08-17 09:00:00', 'Asia/Damascus')]);
        $session->user->update(['name' => 'Sara']);
        Carbon::setTestNow(Carbon::parse('2026-08-17 20:30:00', 'Asia/Damascus'));

        $this->artisan('reception:close-overdue-sessions')->assertExitCode(0);

        $text = $this->noticeText($this->assertNoticed($this->staff, 'reception.forgotten_session_alert', NotificationAudience::Staff), 'en');
        $this->assertSame('The session of Sara at Hall was closed because no check-out was recorded.', $text['body']);
        Carbon::setTestNow();
    }
}
