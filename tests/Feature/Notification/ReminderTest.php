<?php

namespace Tests\Feature\Notification;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Domain\Membership\Models\Membership;
use App\Domain\Providers\Enums\Payer;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use App\Services\Notification\ReminderRunner;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Console\Scheduling\Schedule;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\Feature\Renewals\RenewalsTestCase;

/**
 * Notices a time brings, not an action: each sent once, inside its window,
 * for a subject still live — never late, never for something cancelled.
 */
class ReminderTest extends RenewalsTestCase
{
    use ReadsNotices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
    }

    public function test_a_booking_starting_soon_is_reminded_once(): void
    {
        $soon = Booking::factory()->create(['start_at' => now()->addMinutes(20), 'end_at' => now()->addMinutes(80)]);
        $cancelled = Booking::factory()->create(['start_at' => now()->addMinutes(20), 'end_at' => now()->addMinutes(80), 'status' => BookingStatus::Cancelled]);
        $later = Booking::factory()->create(['start_at' => now()->addMinutes(50), 'end_at' => now()->addMinutes(110)]);
        $started = Booking::factory()->create(['start_at' => now()->subMinutes(5), 'end_at' => now()->addMinutes(55)]);

        $this->runTwice();

        $this->assertNoticed($soon->user_id, 'booking.reminder');
        $this->assertNotNoticed($cancelled->user_id, 'booking.reminder');
        $this->assertNotNoticed($later->user_id, 'booking.reminder');
        $this->assertNotNoticed($started->user_id, 'booking.reminder');
    }

    public function test_a_booking_in_use_ending_soon_is_reminded(): void
    {
        $inUse = Booking::factory()->checkedIn()->create(['start_at' => now()->subMinutes(50), 'end_at' => now()->addMinutes(8)]);
        $neverCame = Booking::factory()->create(['start_at' => now()->subMinutes(50), 'end_at' => now()->addMinutes(8)]);

        $this->runTwice();

        $this->assertSame('8', (string) $this->assertNoticed($inUse->user_id, 'booking.ending_soon')->data['params']['minutes']);
        $this->assertNotNoticed($neverCame->user_id, 'booking.ending_soon');
    }

    public function test_an_event_is_reminded_a_day_and_two_hours_before(): void
    {
        $event = Event::factory()->create(['start_at' => now()->addHours(20), 'end_at' => now()->addHours(22), 'location' => 'Hall A', 'status' => 'published']);
        $approved = EventAttendee::factory()->create(['event_id' => $event->id, 'status' => EventRegistrationStatus::Approved]);
        $pending = EventAttendee::factory()->create(['event_id' => $event->id, 'status' => EventRegistrationStatus::Pending]);

        $this->runTwice();
        $this->travelTo($event->start_at->copy()->subHour());
        $this->runTwice();

        $notices = $this->notices($approved->user_id, 'event.reminder');
        $this->assertCount(2, $notices);
        $this->assertStringContainsString('Hall A', $this->noticeText($notices->first(), 'en')['body']);
        $this->assertNotNoticed($pending->user_id, 'event.reminder');
    }

    public function test_an_unpaid_session_is_reminded_a_day_later(): void
    {
        $unpaid = WalkinSession::factory()->create(['checked_in_at' => now()->subHours(27), 'checked_out_at' => now()->subHours(25), 'payment_state' => PaymentState::Unpaid, 'price_points' => 200]);
        $paid = WalkinSession::factory()->create(['checked_in_at' => now()->subHours(27), 'checked_out_at' => now()->subHours(25), 'payment_state' => PaymentState::Paid, 'price_points' => 200]);
        $old = WalkinSession::factory()->create(['checked_in_at' => now()->subDays(3), 'checked_out_at' => now()->subDays(3)->addHour(), 'payment_state' => PaymentState::Unpaid, 'price_points' => 200]);

        $this->runTwice();

        $this->assertSame(200, $this->assertNoticed($unpaid->user_id, 'coworking.unpaid_reminder')->data['params']['points']);
        $this->assertNotNoticed($paid->user_id, 'coworking.unpaid_reminder');
        $this->assertNotNoticed($old->user_id, 'coworking.unpaid_reminder');
    }

    public function test_a_session_whose_plan_cover_ends_soon_is_reminded_once(): void
    {
        $covered = fn (?string $until, string $status = 'covered') => ['status' => $status, 'reason' => null, 'plan' => null, 'hours_total' => 10.0, 'hours_remaining' => 1.0, 'window' => null, 'covered_until' => $until];

        $endingSoon = WalkinSession::factory()->create(['checked_in_at' => now()->subMinutes(50), 'coverage_snapshot' => $covered(now()->addMinutes(10)->toIso8601String())]);
        $endingLater = WalkinSession::factory()->create(['checked_in_at' => now()->subMinutes(30), 'coverage_snapshot' => $covered(now()->addMinutes(40)->toIso8601String())]);
        $alreadyEnded = WalkinSession::factory()->create(['checked_in_at' => now()->subHours(2), 'coverage_snapshot' => $covered(now()->subMinutes(5)->toIso8601String())]);
        $checkedOut = WalkinSession::factory()->create(['checked_in_at' => now()->subMinutes(50), 'checked_out_at' => now()->subMinute(), 'coverage_snapshot' => $covered(now()->addMinutes(10)->toIso8601String())]);
        $notCovered = WalkinSession::factory()->create(['checked_in_at' => now()->subMinutes(50), 'coverage_snapshot' => $covered(null, 'not_covered')]);

        $this->runTwice();

        $notice = $this->assertNoticed($endingSoon->user_id, 'coworking.plan_cover_ending');
        $this->assertSame('10', (string) $notice->data['params']['minutes']);
        $this->assertCount(1, $this->notices($endingSoon->user_id, 'coworking.plan_cover_ending'));
        $this->assertNotNoticed($endingLater->user_id, 'coworking.plan_cover_ending');
        $this->assertNotNoticed($alreadyEnded->user_id, 'coworking.plan_cover_ending');
        $this->assertNotNoticed($checkedOut->user_id, 'coworking.plan_cover_ending');
        $this->assertNotNoticed($notCovered->user_id, 'coworking.plan_cover_ending');
    }

    public function test_a_plan_is_reminded_a_week_and_a_day_before_it_ends(): void
    {
        $plan = Membership::factory()->create(['current_period_start' => now()->subDays(24), 'current_period_end' => now()->addDays(6), 'auto_renew' => false]);
        $renewing = Membership::factory()->create(['current_period_start' => now()->subDays(24), 'current_period_end' => now()->addDays(6), 'auto_renew' => true]);
        $renewed = Membership::factory()->create(['current_period_start' => now()->subDays(24), 'current_period_end' => now()->addDays(6), 'auto_renew' => false]);
        Membership::factory()->create(['owner_id' => $renewed->owner_id, 'renewed_from_id' => $renewed->id, 'current_period_start' => $renewed->current_period_end, 'current_period_end' => $renewed->current_period_end->copy()->addDays(30)]);

        $this->runTwice();
        $this->travelTo($plan->current_period_end->copy()->subHours(12));
        $this->runTwice();

        $this->assertCount(2, $this->notices($plan->owner_id, 'membership.expiring_soon'));
        $this->assertNotNoticed($renewing->owner_id, 'membership.expiring_soon');
        $this->assertNotNoticed($renewed->owner_id, 'membership.expiring_soon');
    }

    public function test_an_ended_plan_is_told_once_unless_long_gone(): void
    {
        $ended = Membership::factory()->create(['current_period_start' => now()->subDays(30), 'current_period_end' => now()->subHours(2)]);
        $longGone = Membership::factory()->create(['current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDays(3)]);

        $this->runTwice();

        $this->assertNoticed($ended->owner_id, 'membership.expired');
        $this->assertNotNoticed($longGone->owner_id, 'membership.expired');
    }

    public function test_a_lease_a_month_from_its_end_tells_the_admins(): void
    {
        $this->actingAsAdmin();
        [$company, $admin, $doorHolder] = $this->companyWithAdmin();
        $lease = app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(withLock: false)), 'contract_ref' => 'C']),
            auth()->user(),
        );
        $lease->update(['ends_on' => now()->addDays(28)->toDateString(), 'auto_renew' => false]);

        $this->runTwice();

        $this->assertNoticed($admin, 'lease.ending_soon');
        $this->assertNotNoticed($doorHolder, 'lease.ending_soon');
    }

    public function test_an_overdue_invoice_tells_its_payer_once(): void
    {
        [$company, $admin] = $this->companyWithAdmin();
        $overdue = Invoice::factory()->create(['company_id' => $company->id, 'provider_id' => null, 'billed_to' => Payer::Company, 'amount' => '100', 'paid_amount' => '40', 'status' => InvoiceStatus::PartiallyPaid, 'due_at' => now()->subDay()->toDateString()]);
        Invoice::factory()->create(['company_id' => $company->id, 'provider_id' => null, 'billed_to' => Payer::Company, 'due_at' => now()->subDays(30)->toDateString()]);
        Invoice::factory()->create(['company_id' => $company->id, 'provider_id' => null, 'billed_to' => Payer::Company, 'status' => InvoiceStatus::Paid, 'paid_amount' => '100', 'due_at' => now()->subDay()->toDateString()]);

        $this->runTwice();

        $notice = $this->assertNoticed($admin, 'invoice.overdue');
        $this->assertSame('#'.$overdue->id, $notice->data['params']['number']);
        $this->assertSame('60', $notice->data['params']['amount']);
    }

    public function test_the_reminders_run_every_five_minutes(): void
    {
        $this->artisan('notifications:send-reminders')->assertSuccessful();

        $event = collect(app(Schedule::class)->events())->sole(fn ($event) => str_contains((string) $event->command, 'notifications:send-reminders'));
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    private function runTwice(): void
    {
        app(ReminderRunner::class)->run();
        app(ReminderRunner::class)->run();
    }

    /** @return array{Company, User, User} a company, its admin and a member with door access */
    private function companyWithAdmin(): array
    {
        $company = Company::factory()->create(['provider_id' => null]);
        [$admin, $doorHolder] = [User::factory()->create(), User::factory()->create()];
        $company->members()->attach($admin->id, ['door_access_enabled' => false, 'is_admin' => true]);
        $company->members()->attach($doorHolder->id, ['door_access_enabled' => true, 'is_admin' => false]);

        return [$company, $admin, $doorHolder];
    }
}
