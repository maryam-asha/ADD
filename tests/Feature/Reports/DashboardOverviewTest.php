<?php

namespace Tests\Feature\Reports;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Enums\SiteInquiryStatus;
use App\Domain\Ecosystem\Models\SiteInquiry;
use App\Domain\Finance\Enums\CashRegisterSessionStatus;
use App\Domain\Finance\Enums\ExchangeRateSuggestionStatus;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Models\CashRegisterSession;
use App\Domain\Finance\Models\ExchangeRateSuggestion;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Providers\Enums\ProviderRequestStatus;
use App\Domain\Providers\Models\ProviderRequest;
use App\Domain\Renewals\Enums\RenewalStopReason;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Invoicing\InvoicingTestCase;

class DashboardOverviewTest extends InvoicingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 12:00 in Damascus (UTC+3).
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC'));
    }

    private function space(Branch $branch, SpaceType $type, int $capacity = 6): Space
    {
        return Space::factory()->ofType($type)->create([
            'building_id' => Building::factory()->create(['branch_id' => $branch->id])->id,
            'capacity' => $capacity,
        ]);
    }

    private function serviceRequest(RequestStatus $status, ?Branch $branch = null): ServiceRequest
    {
        static $made = 0;
        $made++;

        $axis = ServiceAxis::create(['slug' => "axis-{$made}", 'name' => ['en' => 'Axis', 'ar' => 'محور'], 'color' => '#334155']);
        $type = ServiceType::create(['service_axis_id' => $axis->id, 'slug' => "type-{$made}", 'name' => ['en' => 'Type', 'ar' => 'نوع'], 'request_method' => 'form', 'is_active' => true]);

        return ServiceRequest::create([
            'service_type_id' => $type->id,
            'user_id' => User::factory()->create()->id,
            'branch_id' => $branch?->id,
            'status' => $status,
        ]);
    }

    public function test_each_section_is_shown_only_to_whoever_holds_its_permission(): void
    {
        Role::findByName('operations', 'web')->givePermissionTo('dashboard.operations');
        $staff = User::factory()->create();
        $staff->assignRole('operations');
        Sanctum::actingAs($staff, ['*']);

        $this->getJson('/api/v1/admin/dashboard/operations')->assertOk();
        $this->getJson('/api/v1/admin/dashboard/finance')->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard/members')->assertForbidden();
        $this->getJson('/api/v1/admin/dashboard/spaces')->assertForbidden();

        $this->actingAsAdmin();

        foreach (['operations', 'finance', 'members', 'spaces'] as $section) {
            $this->getJson("/api/v1/admin/dashboard/{$section}")->assertOk();
        }
    }

    public function test_operations_counts_each_branchs_private_offices_and_those_leased_today(): void
    {
        $branch = Branch::factory()->create();
        $leased = $this->space($branch, SpaceType::Business, 2);
        $inMaintenance = $this->space($branch, SpaceType::Business, 2);
        $inMaintenance->update(['status' => OperationalStatus::Maintenance]);
        $terminated = $this->space($branch, SpaceType::Business, 2);
        $later = $this->space($branch, SpaceType::Business, 2);
        $this->space($branch, SpaceType::Business, 2);
        $retired = $this->space($branch, SpaceType::Business, 2);
        $retired->update(['status' => OperationalStatus::Retired]);
        $this->space($branch, SpaceType::Room);

        OfficeLease::factory()->create(['space_id' => $leased->id, 'starts_on' => '2026-06-01']);
        // Leased while under maintenance: still held, still an office of the branch.
        OfficeLease::factory()->create(['space_id' => $inMaintenance->id, 'starts_on' => '2026-10-15', 'duration_months' => 1]);
        // terminated_on is the first day without access.
        OfficeLease::factory()->create(['space_id' => $terminated->id, 'starts_on' => '2026-06-01', 'terminated_on' => '2026-10-15', 'status' => OfficeLeaseStatus::Terminated]);
        OfficeLease::factory()->create(['space_id' => $later->id, 'starts_on' => '2026-11-01']);
        // Another branch's office counts there, not here.
        OfficeLease::factory()->create(['space_id' => $this->space(Branch::factory()->create(), SpaceType::Business, 2)->id, 'starts_on' => '2026-06-01']);

        $this->actingAsAdmin();
        $data = $this->getJson('/api/v1/admin/dashboard/operations')->assertOk()->json('data');

        $here = collect($data['now']['branches'])->firstWhere('id', $branch->id);
        $this->assertSame(5, $here['offices']);
        $this->assertSame(2, $here['offices_leased']);
    }

    public function test_operations_shows_who_is_inside_who_is_expected_and_what_waits_on_staff(): void
    {
        $branch = Branch::factory()->create();
        $coSpace = $this->space($branch, SpaceType::CoSpace, 10);
        $room = $this->space($branch, SpaceType::Room);

        // Inside now: a walk-in and a checked-in booking on the co-working floor.
        WalkinSession::factory()->create(['space_id' => $coSpace->id, 'checked_in_at' => now()->subHour()]);
        Booking::factory()->create(['space_id' => $coSpace->id, 'start_at' => now()->subHour(), 'end_at' => now()->addHour(), 'checked_in_at' => now()->subHour()]);
        WalkinSession::factory()->create(['space_id' => $coSpace->id, 'checked_in_at' => now()->subHours(3), 'checked_out_at' => now()->subHours(2)]);

        // Expected later today, one of them still waiting for approval.
        Booking::factory()->create(['space_id' => $room->id, 'start_at' => now()->addHours(2), 'end_at' => now()->addHours(3)]);
        Booking::factory()->pending()->create(['space_id' => $room->id, 'start_at' => now()->addHours(4), 'end_at' => now()->addHours(5)]);
        // Tomorrow, and one held for its payment: neither is expected today.
        Booking::factory()->create(['space_id' => $room->id, 'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHour()]);
        Booking::factory()->create(['space_id' => $room->id, 'status' => BookingStatus::AwaitingPayment, 'start_at' => now()->addDays(2), 'end_at' => now()->addDays(2)->addHour(), 'payment_due_at' => now()->addHour()]);

        $this->serviceRequest(RequestStatus::Pending, $branch);
        $this->serviceRequest(RequestStatus::InProgress, $branch);
        $this->serviceRequest(RequestStatus::Resolved, $branch);
        SiteInquiry::create(['name' => 'A', 'phone' => '+963911111111', 'message' => 'Hi']);
        SiteInquiry::create(['name' => 'B', 'phone' => '+963922222222', 'message' => 'Hi', 'status' => SiteInquiryStatus::Resolved]);
        PrivateOfficeRequest::factory()->create();
        PrivateOfficeRequest::factory()->create(['status' => PrivateOfficeRequestStatus::Quoted]);

        $this->actingAsAdmin();
        $data = $this->getJson('/api/v1/admin/dashboard/operations')->assertOk()->json('data');

        $this->assertSame(2, $data['now']['active_sessions']);
        $this->assertSame(2, $data['now']['expected_today']);
        $seats = collect($data['now']['branches'])->firstWhere('id', $branch->id);
        $this->assertSame(10, $seats['seats']);
        $this->assertSame(2, $seats['taken']);

        $this->assertSame([
            'bookings_awaiting_approval' => 1,
            'bookings_awaiting_payment' => 1,
            'open_service_requests' => 2,
            'new_site_inquiries' => 1,
            'unquoted_office_requests' => 1,
        ], $data['attention']);
    }

    public function test_operations_counts_bookings_by_outcome_on_local_days_against_the_previous_period(): void
    {
        $room = Space::factory()->room()->create();
        $at = fn (string $utc, BookingStatus $status) => Booking::factory()->create([
            'space_id' => $room->id,
            'status' => $status,
            'start_at' => CarbonImmutable::parse($utc, 'UTC'),
            'end_at' => CarbonImmutable::parse($utc, 'UTC')->addHour(),
        ]);

        // 01:30 on 1 October in Damascus: this period, not the last.
        $at('2026-09-30 22:30:00', BookingStatus::Completed);
        $at('2026-10-05 09:00:00', BookingStatus::Completed);
        $at('2026-10-06 09:00:00', BookingStatus::NoShow);
        $at('2026-10-07 09:00:00', BookingStatus::Cancelled);
        // 23:00 on 30 September in Damascus: the previous period.
        $at('2026-09-30 20:00:00', BookingStatus::Completed);
        $at('2026-09-20 09:00:00', BookingStatus::Completed);
        // Before both windows.
        $at('2026-09-10 09:00:00', BookingStatus::Completed);

        WalkinSession::factory()->create(['space_id' => $room->id, 'checked_in_at' => '2026-10-03 08:00:00', 'checked_out_at' => '2026-10-03 10:00:00']);

        $this->actingAsAdmin();
        $data = $this->getJson('/api/v1/admin/dashboard/operations?from=2026-10-01&to=2026-10-15')->assertOk()->json('data');

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-15'], $data['period']);
        $this->assertSame(['from' => '2026-09-16', 'to' => '2026-09-30'], $data['previous_period']);
        $this->assertSame([
            'bookings' => 4,
            'completed' => 2,
            'no_show' => 1,
            'cancelled' => 1,
            'no_show_rate' => '33.33',
            'cancellation_rate' => '25',
            'walk_ins' => 1,
        ], $data['activity']['current']);
        $this->assertSame([
            'bookings' => 2,
            'completed' => 2,
            'no_show' => 0,
            'cancelled' => 0,
            'no_show_rate' => '0',
            'cancellation_rate' => '0',
            'walk_ins' => 0,
        ], $data['activity']['previous']);
    }

    public function test_the_branch_filter_keeps_operations_to_that_branch(): void
    {
        $here = Branch::factory()->create();
        $there = Branch::factory()->create();
        $hereRoom = $this->space($here, SpaceType::Room);
        $thereRoom = $this->space($there, SpaceType::Room);

        Booking::factory()->pending()->create(['space_id' => $hereRoom->id, 'start_at' => now()->addHours(2), 'end_at' => now()->addHours(3)]);
        Booking::factory()->pending()->create(['space_id' => $thereRoom->id, 'start_at' => now()->addHours(2), 'end_at' => now()->addHours(3)]);
        WalkinSession::factory()->create(['space_id' => $thereRoom->id]);
        $this->serviceRequest(RequestStatus::Pending, $there);
        // Not tied to any branch: counted whatever the filter.
        SiteInquiry::create(['name' => 'A', 'phone' => '+963911111111', 'message' => 'Hi']);

        $this->actingAsAdmin();
        $data = $this->getJson("/api/v1/admin/dashboard/operations?branch_id={$here->id}")->assertOk()->json('data');

        $this->assertSame(0, $data['now']['active_sessions']);
        $this->assertSame(1, $data['now']['expected_today']);
        $this->assertSame([$here->id], collect($data['now']['branches'])->pluck('id')->all());
        $this->assertSame(1, $data['attention']['bookings_awaiting_approval']);
        $this->assertSame(0, $data['attention']['open_service_requests']);
        $this->assertSame(1, $data['attention']['new_site_inquiries']);
    }

    public function test_finance_reports_money_per_currency_and_what_waits_on_staff(): void
    {
        // This period (defaults to the month so far).
        Payment::factory()->withoutGateway('cash', 100)->accepted()->create([
            'purpose' => PaymentPurpose::Booking,
            'received_amount' => '150000',
            'received_currency' => 'SYP',
        ]);
        Payment::factory()->accepted()->create(['charged_amount' => 10, 'currency' => 'USD', 'points_to_credit' => 1000]);
        Payment::factory()->create(['charged_amount' => 99, 'currency' => 'USD']);
        // The previous period.
        Payment::factory()->accepted()->create(['charged_amount' => 5, 'currency' => 'USD', 'points_to_credit' => 500, 'created_at' => '2026-09-20 09:00:00']);

        $overdue = Invoice::factory()->create(['amount' => '200.00', 'computed_amount' => '200.00', 'issued_at' => '2026-08-20', 'due_at' => '2026-09-01']);
        Invoice::factory()->create(['currency' => 'SYP', 'amount' => '40000.00', 'computed_amount' => '40000.00']);
        InvoicePayment::factory()->create(['invoice_id' => $overdue->id, 'received_amount' => '40.00', 'received_currency' => 'USD']);
        InvoicePayment::factory()->create(['invoice_id' => $overdue->id, 'received_amount' => '70.00', 'received_currency' => 'USD', 'voided_at' => now()]);

        ProviderRequest::factory()->create();
        ProviderRequest::factory()->create(['status' => ProviderRequestStatus::Approved]);
        ExchangeRateSuggestion::factory()->create();
        ExchangeRateSuggestion::factory()->create(['status' => ExchangeRateSuggestionStatus::Dismissed]);
        $branch = Branch::factory()->create();
        CashRegisterSession::create(['user_id' => User::factory()->create()->id, 'branch_id' => $branch->id, 'opened_at' => now()]);
        CashRegisterSession::create(['user_id' => User::factory()->create()->id, 'branch_id' => $branch->id, 'opened_at' => now()->subDay(), 'closed_at' => now()->subHours(20), 'status' => CashRegisterSessionStatus::Closed]);
        OfficeLease::factory()->create(['renewal_stop_reason' => RenewalStopReason::OverdueInvoice]);

        $this->actingAsAdmin();
        $data = $this->getJson('/api/v1/admin/dashboard/finance')->assertOk()->json('data');

        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-15'], $data['period']);
        $current = $data['collected']['current'];
        $this->assertSame([['currency' => 'SYP', 'amount' => '150000']], $current['cash']);
        $this->assertSame([['currency' => 'USD', 'amount' => '10']], $current['card']);
        $this->assertSame([['currency' => 'USD', 'amount' => '40']], $current['invoice_payments']);
        $this->assertSame(['booking' => 100, 'wallet_top_up' => 1000], collect($current['points_by_purpose'])->pluck('points', 'purpose')->sortKeys()->all());
        $this->assertSame([['currency' => 'SYP', 'amount' => '40000']], $current['invoiced']);
        $this->assertSame([['currency' => 'USD', 'amount' => '5']], $data['collected']['previous']['card']);

        $usd = collect($data['invoices']['totals'])->firstWhere('currency', 'USD');
        $this->assertSame('200', $usd['overdue']);
        $this->assertSame('40000', collect($data['invoices']['totals'])->firstWhere('currency', 'SYP')['remaining']);
        $this->assertSame('200', collect($data['invoices']['aging'])->firstWhere('currency', 'USD')['31_60']);

        $this->assertSame([
            'pending_provider_requests' => 1,
            'stopped_renewals' => 1,
            'pending_exchange_rate_suggestions' => 1,
            'open_cash_sessions' => 1,
        ], $data['attention']);
    }

    public function test_members_shows_growth_and_what_is_about_to_end(): void
    {
        $member = fn (string $createdAt) => tap(User::factory()->create(['created_at' => $createdAt]))->assignRole('member');
        // 01:30 on 1 October in Damascus: this period.
        $member('2026-09-30 22:30:00');
        $member('2026-10-10 09:00:00');
        $member('2026-09-20 09:00:00');
        tap(User::factory()->create())->assignRole('operations');

        $planA = Plan::factory()->create();
        $planB = Plan::factory()->create();
        $live = fn (Plan $plan, int $endsInDays) => Membership::factory()->create([
            'plan_id' => $plan->id,
            'current_period_start' => now()->subDays(10),
            'current_period_end' => now()->addDays($endsInDays),
        ]);
        $live($planA, 5);
        $live($planA, 20);
        $live($planB, 60);
        // Ends in three days but already renewed: live, not ending.
        $renewed = $live($planA, 3);
        Membership::factory()->create(['plan_id' => $planA->id, 'renewed_from_id' => $renewed->id, 'current_period_start' => now()->addDays(3), 'current_period_end' => now()->addDays(33)]);
        // Over.
        Membership::factory()->create(['plan_id' => $planB->id, 'current_period_start' => now()->subDays(40), 'current_period_end' => now()->subDays(10)]);

        OfficeLease::factory()->create(['starts_on' => '2026-06-01', 'duration_months' => 4, 'ends_on' => '2026-10-24']);
        OfficeLease::factory()->create(['starts_on' => '2026-06-01', 'duration_months' => 12, 'ends_on' => '2027-05-31']);
        OfficeLease::factory()->create(['starts_on' => '2026-06-01', 'duration_months' => 12, 'ends_on' => '2027-05-31', 'terminated_on' => '2026-10-01', 'status' => OfficeLeaseStatus::Terminated]);

        $this->actingAsAdmin();
        $data = $this->getJson('/api/v1/admin/dashboard/members?from=2026-10-01&to=2026-10-15')->assertOk()->json('data');

        $this->assertSame(['current' => 2, 'previous' => 1], $data['new_members']);
        $this->assertSame(4, $data['memberships']['live']);
        $this->assertSame([$planA->id => 3, $planB->id => 1], collect($data['memberships']['by_plan'])->pluck('count', 'plan.id')->sortKeys()->all());
        $this->assertSame(1, $data['memberships']['ending_within_7_days']);
        $this->assertSame(2, $data['memberships']['ending_within_30_days']);
        $this->assertSame(['active' => 2, 'ending_within_30_days' => 1], $data['office_leases']);
    }

    public function test_the_branch_filter_keeps_members_to_that_branch(): void
    {
        $here = Branch::factory()->create();
        $there = Branch::factory()->create();
        Membership::factory()->create(['branch_id' => $here->id]);
        Membership::factory()->create(['branch_id' => $there->id]);

        $this->actingAsAdmin();
        $data = $this->getJson("/api/v1/admin/dashboard/members?branch_id={$here->id}")->assertOk()->json('data');

        $this->assertSame(1, $data['memberships']['live']);
    }

    /** A branch open 09:00–17:00 every day, local time. */
    private function openBranch(): Branch
    {
        $branch = Branch::factory()->create();

        foreach (DayOfWeek::cases() as $day) {
            BusinessHour::factory()->create(['branch_id' => $branch->id, 'day_of_week' => $day, 'open_time' => '09:00', 'close_time' => '17:00']);
        }

        return $branch;
    }

    /** A booking at local Damascus times (UTC+3). */
    private function bookingAt(Space $space, string $localStart, int $minutes, BookingStatus $status, ?User $user = null): Booking
    {
        $start = CarbonImmutable::parse($localStart, 'Asia/Damascus')->utc();

        return Booking::factory()->create([
            'space_id' => $space->id,
            'user_id' => ($user ?? User::factory()->create())->id,
            'status' => $status,
            'start_at' => $start,
            'end_at' => $start->addMinutes($minutes),
        ]);
    }

    public function test_spaces_reports_each_space_over_the_period(): void
    {
        $branch = $this->openBranch();
        $room = $this->space($branch, SpaceType::Room);
        $quiet = $this->space($branch, SpaceType::Room);
        $coSpace = $this->space($branch, SpaceType::CoSpace, 10);
        $office = $this->space($branch, SpaceType::Business);

        // Monday 5 October, 10:00–12:00, and Tuesday 10:00–11:00 nobody came.
        $this->bookingAt($room, '2026-10-05 10:00', 120, BookingStatus::Completed);
        $this->bookingAt($room, '2026-10-06 10:00', 60, BookingStatus::NoShow);
        $this->bookingAt($room, '2026-10-07 10:00', 60, BookingStatus::Cancelled);
        // The period before.
        $this->bookingAt($room, '2026-10-01 10:00', 60, BookingStatus::Completed);
        $this->bookingAt($coSpace, '2026-10-05 13:00', 60, BookingStatus::Completed);
        $this->bookingAt($office, '2026-10-05 13:00', 60, BookingStatus::Completed);

        $this->actingAsAdmin();
        $data = $this->getJson('/api/v1/admin/dashboard/spaces?from=2026-10-05&to=2026-10-11')->assertOk()->json('data');

        $rows = collect($data['spaces'])->keyBy('space.id');
        $this->assertSame([$room->id, $coSpace->id, $quiet->id], collect($data['spaces'])->pluck('space.id')->all());

        $this->assertSame([
            'bookings' => 2,
            'no_show_rate' => '50',
            'cancellation_rate' => '33.33',
            'average_per_day' => '0.28',
            'average_per_week' => '2',
            'average_duration_minutes' => 90,
            'booked_hours' => '3',
            // 180 booked of 7 × 480 open minutes.
            'occupancy_percent' => '5.35',
            'members' => 2,
        ], collect($rows[$room->id])->except('space')->all());

        $this->assertSame(0, $rows[$quiet->id]['bookings']);
        $this->assertSame('0', $rows[$quiet->id]['occupancy_percent']);
        $this->assertNull($rows[$quiet->id]['no_show_rate']);
        // The Co-Space's seats are the branch pool's: no occupancy of its own.
        $this->assertSame(1, $rows[$coSpace->id]['bookings']);
        $this->assertNull($rows[$coSpace->id]['occupancy_percent']);

        $this->assertSame(3, $data['summary']['current']['bookings']);
        // 180 booked of the two rooms' 2 × 3360 open minutes; the Co-Space has none.
        $this->assertSame('2.67', $data['summary']['current']['occupancy_percent']);
        $this->assertSame(1, $data['summary']['previous']['bookings']);

        // Booked minutes by local weekday (0 = Sunday) and hour.
        $this->assertSame(60, $data['peak']['minutes'][1][10]);
        $this->assertSame(60, $data['peak']['minutes'][1][11]);
        $this->assertSame(60, $data['peak']['minutes'][1][13]);
        $this->assertSame(60, $data['peak']['minutes'][2][10]);
        $this->assertSame(1, $data['peak']['busiest_weekday']);
        $this->assertSame(10, $data['peak']['busiest_hour']);
    }

    public function test_spaces_narrow_to_a_type_or_one_space(): void
    {
        $branch = $this->openBranch();
        $room = $this->space($branch, SpaceType::Room);
        $hall = $this->space($branch, SpaceType::EventHall);
        $this->bookingAt($room, '2026-10-05 10:00', 60, BookingStatus::Completed);
        $this->bookingAt($hall, '2026-10-06 14:00', 60, BookingStatus::Completed);

        $this->actingAsAdmin();

        $halls = $this->getJson('/api/v1/admin/dashboard/spaces?space_type=event_hall&from=2026-10-01&to=2026-10-15')->assertOk()->json('data');
        $this->assertSame([$hall->id], collect($halls['spaces'])->pluck('space.id')->all());

        $one = $this->getJson("/api/v1/admin/dashboard/spaces?space_id={$room->id}&from=2026-10-01&to=2026-10-15")->assertOk()->json('data');
        $this->assertSame([$room->id], collect($one['spaces'])->pluck('space.id')->all());
        $this->assertSame(0, $one['peak']['minutes'][2][14]);
        $this->assertSame(60, $one['peak']['minutes'][1][10]);
    }

    public function test_spaces_cover_a_year_at_most(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/dashboard/spaces?from=2025-01-01&to=2026-10-15')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/admin/dashboard/spaces?space_type=business')->assertUnprocessable()->assertJsonValidationErrors('space_type');
    }

    public function test_the_period_must_be_in_order(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/dashboard/finance?from=2026-10-10&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/admin/dashboard/operations?branch_id=999999')->assertUnprocessable()->assertJsonValidationErrors('branch_id');
    }
}
