<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Enums\SessionStartSource;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Device;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
            'api.sciener.test/v3/lock/unlock' => Http::response(['errcode' => 0, 'errmsg' => ''], 200),
        ]);
    }

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
        Sanctum::actingAs($member, ['member-app']);
        $this->postJson('/api/v1/member/access/unlock', ['qr_value' => 'room-door'])->assertOk();
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

    public function test_an_early_open_still_opens_the_door_but_starts_nothing(): void
    {
        [$member, $booking] = $this->bookedRoom(now()->addHour());

        $this->openDoorAs($member);

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
}
