<?php

namespace Tests\Feature\Reports;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\DeviceToken;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Invoicing\InvoicingTestCase;

class DashboardStatsTest extends InvoicingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 12:00 in Damascus (UTC+3).
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC'));
    }

    private function space(Branch $branch, SpaceType $type): Space
    {
        return Space::factory()->ofType($type)->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
        ]);
    }

    private function booking(Space $space, string $startUtc, BookingStatus $status): Booking
    {
        $start = CarbonImmutable::parse($startUtc, 'UTC');

        return Booking::factory()->create(['space_id' => $space->id, 'status' => $status, 'start_at' => $start, 'end_at' => $start->addHour()]);
    }

    private function serviceType(string $slug, string $name): ServiceType
    {
        $axis = ServiceAxis::create(['slug' => "axis-{$slug}", 'name' => ['en' => 'Axis', 'ar' => 'محور'], 'color' => '#334155']);

        return ServiceType::create(['service_axis_id' => $axis->id, 'slug' => $slug, 'name' => ['en' => $name, 'ar' => $name], 'request_method' => 'form', 'is_active' => true]);
    }

    private function serviceRequest(ServiceType $type, RequestStatus $status, ?Branch $branch = null, string $submittedAt = '2026-10-10 09:00:00'): ServiceRequest
    {
        return ServiceRequest::create([
            'service_type_id' => $type->id,
            'user_id' => User::factory()->create()->id,
            'branch_id' => $branch?->id,
            'status' => $status,
            'submitted_at' => $submittedAt,
        ]);
    }

    public function test_each_card_needs_its_sections_permission(): void
    {
        Role::findByName('operations', 'web')->givePermissionTo('dashboard.operations');
        $staff = User::factory()->create();
        $staff->assignRole('operations');
        Sanctum::actingAs($staff, ['*']);

        $this->getJson('/api/v1/admin/dashboard/stats/bookings-by-status')->assertOk();
        $this->getJson('/api/v1/admin/dashboard/stats/service-requests')->assertOk();
        $this->getJson('/api/v1/admin/dashboard/stats/daily-bookings')->assertOk();
        $this->getJson('/api/v1/admin/dashboard/stats/daily-members')->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard/stats/app-users')->assertForbidden();
    }

    public function test_bookings_by_status_lists_every_status_on_local_days(): void
    {
        $branch = Branch::factory()->create();
        $room = $this->space($branch, SpaceType::Room);
        $hall = $this->space($branch, SpaceType::EventHall);
        $elsewhere = $this->space(Branch::factory()->create(), SpaceType::Room);

        // 01:30 on 1 October in Damascus: inside the month.
        $this->booking($room, '2026-09-30 22:30:00', BookingStatus::Completed);
        $this->booking($room, '2026-10-05 09:00:00', BookingStatus::NoShow);
        $this->booking($hall, '2026-10-06 09:00:00', BookingStatus::Cancelled);
        $this->booking($elsewhere, '2026-10-06 09:00:00', BookingStatus::Completed);
        // 23:00 on 30 September in Damascus: before it.
        $this->booking($room, '2026-09-30 20:00:00', BookingStatus::Completed);

        $this->actingAsAdmin();

        $all = $this->getJson('/api/v1/admin/dashboard/stats/bookings-by-status')->assertOk()->json('data');
        $this->assertSame(4, $all['total']);
        $this->assertSame(array_map(fn ($s) => $s->value, BookingStatus::cases()), array_keys($all['by_status']));
        $this->assertSame(2, $all['by_status']['completed']);
        $this->assertSame(0, $all['by_status']['pending']);

        $rooms = $this->getJson("/api/v1/admin/dashboard/stats/bookings-by-status?branch_id={$branch->id}&space_type=room")->assertOk()->json('data');
        $this->assertSame(['completed' => 1, 'no_show' => 1, 'cancelled' => 0], array_intersect_key($rooms['by_status'], array_flip(['completed', 'no_show', 'cancelled'])));
    }

    public function test_service_requests_by_status_and_type_with_the_share_resolved(): void
    {
        $branch = Branch::factory()->create();
        $cleaning = $this->serviceType('cleaning', 'Cleaning');
        $support = $this->serviceType('support', 'Support');

        $this->serviceRequest($cleaning, RequestStatus::Resolved, $branch);
        $this->serviceRequest($cleaning, RequestStatus::Pending, $branch);
        $this->serviceRequest($cleaning, RequestStatus::Resolved);
        $this->serviceRequest($support, RequestStatus::Rejected, $branch);
        // Last month: not in the period.
        $this->serviceRequest($support, RequestStatus::Resolved, $branch, '2026-09-10 09:00:00');

        $this->actingAsAdmin();

        $all = $this->getJson('/api/v1/admin/dashboard/stats/service-requests')->assertOk()->json('data');
        $this->assertSame(4, $all['total']);
        $this->assertSame(2, $all['by_status']['resolved']);
        $this->assertSame(0, $all['by_status']['in_progress']);
        $this->assertSame('50', $all['resolution_rate']);
        $this->assertSame([['type' => ['id' => $cleaning->id, 'name' => 'Cleaning'], 'count' => 3], ['type' => ['id' => $support->id, 'name' => 'Support'], 'count' => 1]], $all['by_type']);

        $here = $this->getJson("/api/v1/admin/dashboard/stats/service-requests?branch_id={$branch->id}")->assertOk()->json('data');
        $this->assertSame(3, $here['total']);
        $this->assertSame('33.33', $here['resolution_rate']);
    }

    public function test_daily_cards_give_a_point_for_every_local_day(): void
    {
        $room = $this->space(Branch::factory()->create(), SpaceType::Room);
        $this->booking($room, '2026-09-30 22:30:00', BookingStatus::Completed);
        $this->booking($room, '2026-10-02 09:00:00', BookingStatus::Confirmed);
        $this->booking($room, '2026-10-02 10:00:00', BookingStatus::Cancelled);

        foreach (['2026-10-01 08:00:00', '2026-10-03 08:00:00', '2026-09-20 08:00:00'] as $joined) {
            tap(User::factory()->create(['created_at' => $joined]))->assignRole('member');
        }

        $this->actingAsAdmin();

        $bookings = $this->getJson('/api/v1/admin/dashboard/stats/daily-bookings?from=2026-10-01&to=2026-10-03')->assertOk()->json('data.days');
        $this->assertSame([
            ['date' => '2026-10-01', 'count' => 1],
            ['date' => '2026-10-02', 'count' => 2],
            ['date' => '2026-10-03', 'count' => 0],
        ], $bookings);

        $members = $this->getJson('/api/v1/admin/dashboard/stats/daily-members?from=2026-10-01&to=2026-10-03')->assertOk()->json('data.days');
        $this->assertSame([1, 0, 1], array_column($members, 'count'));
    }

    public function test_app_users_by_platform(): void
    {
        $android = tap(User::factory()->create())->assignRole('member');
        $both = tap(User::factory()->create())->assignRole('member');
        tap(User::factory()->create())->assignRole('member');
        $staff = tap(User::factory()->create())->assignRole('operations');

        DeviceToken::create(['user_id' => $android->id, 'token' => 'a1', 'platform' => 'android']);
        DeviceToken::create(['user_id' => $android->id, 'token' => 'a2', 'platform' => 'android']);
        DeviceToken::create(['user_id' => $both->id, 'token' => 'b1', 'platform' => 'android']);
        DeviceToken::create(['user_id' => $both->id, 'token' => 'b2', 'platform' => 'ios']);
        DeviceToken::create(['user_id' => $staff->id, 'token' => 's1', 'platform' => 'ios']);

        $this->actingAsAdmin();

        $this->assertSame(
            ['members' => 3, 'android' => 2, 'ios' => 1, 'without_app' => 1],
            $this->getJson('/api/v1/admin/dashboard/stats/app-users')->assertOk()->json('data'),
        );
    }
}
