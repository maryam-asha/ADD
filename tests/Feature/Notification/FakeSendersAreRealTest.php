<?php

namespace Tests\Feature\Notification;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Booking\Services\PendingBookingNotice;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Enums\ProviderRequestType;
use App\Domain\Providers\Models\Provider;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Providers\Services\ProviderRequestService;
use App\Services\Experience\EventApprovalService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use App\Support\Enums\EventRegistrationStatus;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * The notices that used to be a `notification_logs` row nobody received
 * now reach their reader, worded with what happened, where and when.
 */
class FakeSendersAreRealTest extends TestCase
{
    use ReadsNotices;
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));

        $this->operator = User::factory()->create(['status' => 'active']);
        $this->operator->assignRole('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_approving_tells_the_member_when_and_where(): void
    {
        $booking = $this->pendingBooking();

        app(BookingApprovalService::class)->approve($booking, $this->operator);

        $notice = $this->assertNoticed($booking->user_id, 'booking.approved_with_payment');
        $this->assertSame(['ar' => 'غرفة الاجتماعات', 'en' => 'Meeting room'], $notice->data['params']['space']);
        $this->assertSame('2026-08-18', $notice->data['params']['date']);
        $this->assertSame('10:00', $notice->data['params']['time']);
        $this->assertStringContainsString('Meeting room', $this->noticeText($notice, 'en')['body']);
    }

    public function test_rejection_without_a_refund_says_nothing_about_points(): void
    {
        $booking = $this->pendingBooking();

        app(BookingApprovalService::class)->reject($booking, $this->operator, 'Closed that day.');

        $notice = $this->assertNoticed($booking->user_id, 'booking.rejected_no_refund');
        $this->assertStringNotContainsString('point', $this->noticeText($notice, 'en')['body']);
        $this->assertNotNoticed($booking->user_id, 'booking.rejected');
    }

    public function test_a_lapsed_booking_is_told(): void
    {
        $booking = $this->pendingBooking();
        Carbon::setTestNow($booking->start_at->copy()->addMinute());

        app(BookingApprovalService::class)->lapse($booking);

        $this->assertNoticed($booking->user_id, 'booking.lapsed_no_refund');
    }

    public function test_a_pending_booking_reaches_whoever_may_approve(): void
    {
        Role::create(['name' => 'desk', 'guard_name' => 'web'])->givePermissionTo('reception.approve');
        $desk = User::factory()->create(['status' => 'active']);
        $desk->assignRole('desk');
        $booking = $this->pendingBooking();

        app(PendingBookingNotice::class)->send($booking);

        $notice = $this->assertNoticed($desk, 'booking.pending_approval', NotificationAudience::Staff);
        $this->assertSame($booking->user->name, $notice->data['params']['member']);
        $this->assertNoticed($this->operator, 'booking.pending_approval', NotificationAudience::Staff);
        $this->assertNotNoticed($booking->user_id, 'booking.pending_approval');
    }

    public function test_event_decisions_reach_the_member(): void
    {
        $event = Event::factory()->create([
            'title' => ['ar' => 'محاضرة', 'en' => 'Talk'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHour(),
            'requires_approval' => true,
        ]);
        $approved = EventAttendee::factory()->create(['event_id' => $event->id, 'status' => EventRegistrationStatus::Pending]);
        $rejected = EventAttendee::factory()->create(['event_id' => $event->id, 'status' => EventRegistrationStatus::Pending]);
        $leftOver = EventAttendee::factory()->create(['event_id' => $event->id, 'status' => EventRegistrationStatus::Pending]);

        app(EventApprovalService::class)->approve($approved, $this->operator);
        app(EventApprovalService::class)->reject($rejected, $this->operator, 'Full');
        app(EventApprovalService::class)->rejectAllPending($event, $this->operator, 'Closed');

        $notice = $this->assertNoticed($approved->user_id, 'event.registration_approved');
        $this->assertSame(['ar' => 'محاضرة', 'en' => 'Talk'], $notice->data['params']['event']);
        $this->assertNoticed($rejected->user_id, 'event.registration_rejected');
        $this->assertNoticed($leftOver->user_id, 'event.registration_rejected');
    }

    public function test_a_registration_left_undecided_past_its_deadline_is_told(): void
    {
        $attendee = EventAttendee::factory()->create([
            'status' => EventRegistrationStatus::Pending,
            'pending_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('events:expire-pending-registrations')->assertSuccessful();

        $this->assertNoticed($attendee->user_id, 'event.registration_rejected');
    }

    public function test_provider_decisions_reach_its_accounts_in_their_portal(): void
    {
        [$provider, $account] = $this->provider();
        $company = Company::factory()->create(['provider_id' => $provider->id, 'legal_name' => 'Acme']);
        $request = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'submitted_by' => $account->id,
            'type' => ProviderRequestType::WalletTopUp,
            'company_id' => $company->id,
            'payload' => ['points' => 100, 'payer' => 'company'],
        ]);

        app(ProviderRequestService::class)->reject($request, $this->operator, 'No contract yet');

        $notice = $this->assertNoticed($account, 'provider.request_rejected', NotificationAudience::Provider);
        $this->assertSame('No contract yet', $notice->data['params']['reason']);
        $this->assertStringContainsString('Acme', $this->noticeText($notice, 'en')['body']);
    }

    public function test_a_request_without_a_company_names_only_its_type(): void
    {
        [$provider, $account] = $this->provider();
        $request = ProviderRequest::factory()->create([
            'provider_id' => $provider->id,
            'submitted_by' => $account->id,
            'type' => ProviderRequestType::PlanDesign,
            'company_id' => null,
            'payload' => ['name' => 'Starter'],
        ]);

        app(ProviderRequestService::class)->reject($request, $this->operator, 'Too cheap');

        $body = $this->noticeText($this->assertNoticed($account, 'provider.request_rejected', NotificationAudience::Provider), 'en')['body'];
        $this->assertStringNotContainsString('for :', $body);
        $this->assertStringNotContainsString('company', strtolower($body));
    }

    private function pendingBooking(): Booking
    {
        $booking = Booking::factory()->pending()->create([
            // Stored in UTC, as the app stores every instant.
            'start_at' => Carbon::parse('2026-08-18 10:00:00', 'Asia/Damascus')->utc(),
            'end_at' => Carbon::parse('2026-08-18 11:00:00', 'Asia/Damascus')->utc(),
        ]);
        $booking->space->update(['name' => ['ar' => 'غرفة الاجتماعات', 'en' => 'Meeting room']]);
        $booking->user->update(['status' => 'active']);

        return $booking->fresh(['space', 'user']);
    }

    /** @return array{Provider, User} */
    private function provider(): array
    {
        $provider = Provider::factory()->create();
        $account = User::factory()->create(['status' => 'active']);
        $account->assignRole('provider');
        $provider->users()->attach($account->id);

        return [$provider, $account];
    }
}
