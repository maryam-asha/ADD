<?php

namespace Tests\Feature\Notification;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Leasing\Data\LeaseTerms;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\LeaseDailyRunner;
use App\Domain\Leasing\Services\OfficeLeaseService;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\PushProvider;
use Carbon\CarbonInterface;
use Tests\Feature\Leasing\LeasingTestCase;
use Tests\Feature\Notification\Concerns\ReadsNotices;

/**
 * A door code ready, a door code stopped for maintenance, a booking a
 * maintenance stop affects, an office whose door opens for the first time:
 * the member hears of each — and never sees a code in a notification's text.
 */
class DoorNoticesTest extends LeasingTestCase
{
    use ReadsNotices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
        $this->fakeTtlock();
        $this->actingAsAdmin();
    }

    public function test_a_door_code_ready_tells_the_member_without_the_code(): void
    {
        $booking = $this->bookingWithALock(now()->addMinutes(30));

        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();

        $notice = $this->assertNoticed($booking->user_id, 'access.passcode_ready');
        $text = $this->noticeText($notice, 'en');
        $this->assertStringNotContainsString('112233', $text['title'].$text['body']);
        $this->assertNotSame('', $notice->data['params']['from']);
    }

    public function test_maintenance_tells_affected_bookings_and_stops_their_codes(): void
    {
        $booking = $this->bookingWithALock(now()->addMinutes(30));
        $later = Booking::factory()->create(['space_id' => $booking->space_id, 'start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour()]);
        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();

        $this->patchJson("/api/v1/admin/spaces/{$booking->space_id}/status", ['status' => OperationalStatus::Maintenance->value])->assertOk();
        $this->artisan('access:revoke-grants-on-maintenance')->assertSuccessful();

        $this->assertNoticed($booking->user_id, 'booking.affected_by_maintenance');
        $this->assertNoticed($later->user_id, 'booking.affected_by_maintenance');
        $this->assertNoticed($booking->user_id, 'access.revoked');
        $this->assertNotNoticed($later->user_id, 'access.revoked');
    }

    public function test_the_first_door_opening_tells_members_with_door_access(): void
    {
        [$withAccess, $without] = [User::factory()->create(), User::factory()->create()];
        $company = Company::factory()->create();
        $company->members()->attach($withAccess->id, ['door_access_enabled' => true, 'is_admin' => false]);
        $company->members()->attach($without->id, ['door_access_enabled' => false, 'is_admin' => false]);

        $this->leaseStartingToday($company);

        $this->assertNoticed($withAccess, 'access.office_opened');
        $this->assertNotNoticed($without, 'access.office_opened');
    }

    public function test_a_renewed_lease_does_not_announce_the_office_again(): void
    {
        $member = User::factory()->create();
        $company = Company::factory()->create();
        $company->members()->attach($member->id, ['door_access_enabled' => true, 'is_admin' => false]);
        $lease = $this->leaseStartingToday($company);

        // The door comes back after maintenance, as on a renewal: not news.
        $lease->space->update(['status' => OperationalStatus::Maintenance]);
        $this->artisan('access:revoke-grants-on-maintenance')->assertSuccessful();
        $lease->space->update(['status' => OperationalStatus::Active]);
        $this->assertSame(1, app(LeaseDailyRunner::class)->run()['doors_opened']);

        $this->assertNoticed($member, 'access.office_opened');
    }

    private function bookingWithALock(CarbonInterface $start): Booking
    {
        $space = Space::factory()->create(['allocation_model' => AllocationModel::BookingHourly]);
        Device::factory()->create(['space_id' => $space->id, 'type' => 'lock', 'external_ref' => '77']);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'status' => BookingStatus::Confirmed,
            'start_at' => $start,
            'end_at' => $start->copy()->addHour(),
        ]);
    }

    private function leaseStartingToday(Company $company): OfficeLease
    {
        return app(OfficeLeaseService::class)->contract(
            PrivateOfficeRequest::factory()->quoted()->create(['company_id' => $company->id]),
            LeaseTerms::fromArray([...$this->leasePayload($this->leasableOffice(), ['starts_on' => now()->toDateString()]), 'contract_ref' => 'C']),
            auth()->user(),
        );
    }
}
