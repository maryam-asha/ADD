<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\BookingDoorWindow;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCheckInService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A booked room has no kiosk scan, so its arrival is seen at the door: the
 * desk activating the booking's door code, or the member opening the door
 * with its QR, checks the booking in — the first of the two, under the same
 * rules as any check-in, and never at the cost of the door itself.
 */
class DoorArrivalChecksInBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        // A Monday, inside the branch's hours below.
        Carbon::setTestNow(Carbon::parse('2026-08-17 10:00:00', 'Asia/Damascus'));
        Http::preventStrayRequests();
        config(['services.ttlock.base_url' => 'https://api.sciener.test']);
        Http::fake([
            'api.sciener.test/oauth2/token' => Http::response(['access_token' => 'tok', 'refresh_token' => 'ref', 'expires_in' => 7776000], 200),
            'api.sciener.test/v3/lock/unlock' => fn () => Http::response($this->unlockReply, 200),
        ]);
    }

    /** What the lock's gateway answers to an unlock; a test sets an error to keep the door shut. */
    private array $unlockReply = ['errcode' => 0, 'errmsg' => ''];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function room(): Space
    {
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        BusinessHour::factory()->create([
            'branch_id' => $space->building->branch_id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '20:00',
        ]);

        return $space;
    }

    /**
     * @return array{0: User, 1: Booking, 2: AccessGrant}
     */
    private function bookedRoom(Carbon $startAt, AccessGrantStatus $status = AccessGrantStatus::Activated): array
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        $booking = Booking::factory()->create([
            'space_id' => $this->room()->id,
            'user_id' => $member->id,
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addHours(2),
        ]);
        $lock = Device::factory()->create(['type' => 'lock', 'qr_value' => 'room-door']);
        $grant = AccessGrant::factory()->create([
            'lock_id' => $lock->id,
            'grantee_type' => OwnerType::User,
            'grantee_id' => $member->id,
            'source_type' => AccessSourceType::Booking,
            'source_id' => $booking->id,
            'status' => $status,
            'activated_at' => $status === AccessGrantStatus::Activated ? now() : null,
            'expires_at' => $booking->end_at,
        ]);

        return [$member, $booking, $grant];
    }

    private function openDoorAs(User $member): void
    {
        $this->scanDoorAs($member)->assertOk();
    }

    private function scanDoorAs(User $member): TestResponse
    {
        Sanctum::actingAs($member, ['member-app']);

        return $this->withHeader('lang', 'en')->postJson('/api/v1/member/scan', ['qr_value' => 'room-door']);
    }

    public function test_opening_the_booked_room_starts_its_session(): void
    {
        [$member, $booking] = $this->bookedRoom(now());

        $this->openDoorAs($member);

        $booking->refresh();
        $this->assertTrue($booking->checked_in_at->equalTo(now()));
        $this->assertSame(SessionStartSource::Door, $booking->checked_in_via);
    }

    public function test_opening_it_again_changes_nothing(): void
    {
        [$member, $booking] = $this->bookedRoom(now());
        $this->openDoorAs($member);
        $first = $booking->fresh()->checked_in_at;

        Carbon::setTestNow(now()->addMinutes(30));
        $this->openDoorAs($member);

        $this->assertTrue($booking->fresh()->checked_in_at->equalTo($first));
    }

    /** A code the desk activated an hour early opens nothing until the early allowance (2026-11-08). */
    public function test_an_activated_code_opens_nothing_before_the_early_allowance(): void
    {
        [$member, $booking] = $this->bookedRoom(now()->addHour());

        $this->scanDoorAs($member)->assertForbidden();

        $this->assertNull($booking->fresh()->checked_in_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v3/lock/unlock'));
    }

    /** Within the early allowance the scan opens the door and checks in; the session still starts at start_at. */
    public function test_arriving_within_the_early_allowance_opens_and_checks_in_from_the_start(): void
    {
        $startAt = now()->addMinutes(BookingCheckInService::EARLY_CHECK_IN_MINUTES - 5);
        [$member, $booking, $grant] = $this->bookedRoom($startAt, AccessGrantStatus::Issued);

        $this->openDoorAs($member);

        $this->assertSame(AccessGrantStatus::Activated, $grant->fresh()->status);
        $this->assertTrue($booking->fresh()->checked_in_at->equalTo($startAt));
    }

    /** The overrun grace is for leaving: a member who came opens the door until it runs out. */
    public function test_after_the_end_the_door_opens_through_the_grace_for_a_member_who_came(): void
    {
        [$member, $booking] = $this->bookedRoom(now()->subHours(2));
        $booking->update(['status' => BookingStatus::Completed, 'checked_in_at' => $booking->start_at, 'checked_out_at' => $booking->end_at]);

        Carbon::setTestNow($booking->end_at->copy()->addMinutes(BookingDoorWindow::DEFAULT_OVERRUN_GRACE_MINUTES - 1));
        $this->openDoorAs($member);

        Carbon::setTestNow($booking->end_at->copy()->addMinutes(BookingDoorWindow::DEFAULT_OVERRUN_GRACE_MINUTES + 1));
        $this->scanDoorAs($member)->assertForbidden();
    }

    public function test_the_grace_follows_its_setting(): void
    {
        app(SettingService::class)->set(BookingDoorWindow::OVERRUN_GRACE_KEY, 0, SettingValueType::Int);
        [$member, $booking] = $this->bookedRoom(now()->subHours(2));
        $booking->update(['checked_in_at' => $booking->start_at]);

        Carbon::setTestNow($booking->end_at->copy()->addMinute());

        $this->scanDoorAs($member)->assertForbidden();
    }

    /** A member who never came is not let in once the booking has ended, even with an activated code. */
    public function test_after_the_end_a_member_who_never_came_is_not_let_in(): void
    {
        [$member, $booking] = $this->bookedRoom(now()->subHours(2));

        Carbon::setTestNow($booking->end_at->copy()->addMinutes(2));

        $this->scanDoorAs($member)->assertForbidden();
        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_a_company_tenancy_code_starts_no_booking(): void
    {
        [$member, $booking, $grant] = $this->bookedRoom(now());
        $grant->update(['source_type' => AccessSourceType::Tenancy, 'source_id' => null]);

        $this->openDoorAs($member);

        $this->assertNull($booking->fresh()->checked_in_at);
    }

    public function test_the_desk_activating_the_door_code_starts_the_session(): void
    {
        [, $booking, $grant] = $this->bookedRoom(now(), AccessGrantStatus::Issued);
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->postJson("/api/v1/admin/reception/access-grants/{$grant->id}/activate")->assertOk();

        $booking->refresh();
        $this->assertNotNull($booking->checked_in_at);
        $this->assertSame(SessionStartSource::Reception, $booking->checked_in_via);
    }

    public function test_activating_early_activates_the_code_and_leaves_the_door_to_start_it(): void
    {
        [$member, $booking, $grant] = $this->bookedRoom(now()->addHour(), AccessGrantStatus::Issued);
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->postJson("/api/v1/admin/reception/access-grants/{$grant->id}/activate")->assertOk();

        $this->assertSame(AccessGrantStatus::Activated, $grant->fresh()->status);
        $this->assertNull($booking->fresh()->checked_in_at);

        Carbon::setTestNow(now()->addHour());
        $this->openDoorAs($member);

        $this->assertSame(SessionStartSource::Door, $booking->fresh()->checked_in_via);
    }

    /** Straight to the room, past the desk: the first open from the app activates the code and starts the session. */
    public function test_the_member_opens_the_room_without_the_desk_and_the_first_open_starts_the_session(): void
    {
        [$member, $booking, $grant] = $this->bookedRoom(now(), AccessGrantStatus::Issued);

        $this->openDoorAs($member);

        $grant->refresh();
        $this->assertSame(AccessGrantStatus::Activated, $grant->status);
        $this->assertTrue($grant->activated_at->equalTo(now()));
        $booking->refresh();
        $this->assertTrue($booking->checked_in_at->equalTo(now()));
        $this->assertSame(SessionStartSource::Door, $booking->checked_in_via);
    }

    /** Before the early allowance the room may still be someone else's: the code opens nothing. */
    public function test_a_code_does_not_open_before_the_early_allowance(): void
    {
        [$member, $booking, $grant] = $this->bookedRoom(now()->addMinutes(BookingCheckInService::EARLY_CHECK_IN_MINUTES + 5), AccessGrantStatus::Issued);

        $this->scanDoorAs($member)->assertForbidden();

        $this->assertSame(AccessGrantStatus::Issued, $grant->fresh()->status);
        $this->assertNull($booking->fresh()->checked_in_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/v3/lock/unlock'));
    }

    public function test_a_cancelled_bookings_code_opens_nothing(): void
    {
        [$member, $booking, $grant] = $this->bookedRoom(now(), AccessGrantStatus::Issued);
        $booking->update(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()]);

        $this->scanDoorAs($member)->assertForbidden();

        $this->assertSame(AccessGrantStatus::Issued, $grant->fresh()->status);
    }

    /** The lock did not open (its gateway is down): nothing is activated and no session starts. */
    public function test_a_door_that_does_not_open_activates_nothing(): void
    {
        $this->unlockReply = ['errcode' => -2012, 'errmsg' => 'The Lock is not connected to any Gateway.'];
        [$member, $booking, $grant] = $this->bookedRoom(now(), AccessGrantStatus::Issued);

        $this->scanDoorAs($member)->assertStatus(503);

        $this->assertSame(AccessGrantStatus::Issued, $grant->fresh()->status);
        $this->assertNull($booking->fresh()->checked_in_at);
    }
}
