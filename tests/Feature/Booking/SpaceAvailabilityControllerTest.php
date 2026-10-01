<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpaceAvailabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        // The seeded hour of notice is off here: these tests pin other rules
        // at a fixed "now", and the notice is tested on its own.
        app(SettingService::class)->set('booking.min_notice_minutes', 0);
        // 2026-08-17 is a Monday.
        Carbon::setTestNow(Carbon::parse('2026-08-17 08:00:00', 'Asia/Damascus'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingAsMember(): User
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        return $member;
    }

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function openSpace(array $attributes = []): Space
    {
        $space = Space::factory()->room()->create(array_merge([
            'name' => ['en' => 'Meeting Room A', 'ar' => 'غرفة اجتماعات أ'],
            'hourly_rate_points' => 100,
            'slot_granularity_minutes' => 60,
        ], $attributes));

        foreach ([DayOfWeek::Sunday, DayOfWeek::Monday, DayOfWeek::Tuesday, DayOfWeek::Wednesday, DayOfWeek::Thursday] as $day) {
            BusinessHour::factory()->create([
                'branch_id' => $space->building->branch_id,
                'day_of_week' => $day,
                'open_time' => '08:00',
                'close_time' => '20:00',
            ]);
        }

        return $space;
    }

    private function book(Space $space, int $hour, int $minutes = 60): Booking
    {
        $start = Carbon::parse('2026-08-17', 'Asia/Damascus')->setTime($hour, 0);

        return Booking::factory()->create([
            'space_id' => $space->id,
            'start_at' => $start->copy()->setTimezone('UTC'),
            'end_at' => $start->copy()->addMinutes($minutes)->setTimezone('UTC'),
        ]);
    }

    /**
     * The member app reads one object, and only what changes with the date
     * or the duration: the day's state and banner, the slots and their
     * counts. The setup it starts from comes once, with the space details.
     */
    public function test_the_member_day_response_carries_only_what_changes_per_date(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk();

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(
            ['date', 'state', 'closed_banner', 'slots', 'counts', 'meta'],
            array_keys($response->json('data')),
        );
        $this->assertCount(12, $response->json('data.slots'));

        // Just the date, echoed so the app can drop a stale answer when the
        // member taps dates faster than they return. The day's name, month
        // and formatted date are the app's to format; the day's state and
        // counts already sit in `state` and `counts`.
        $this->assertSame('2026-08-17', $response->json('data.date'));
    }

    /**
     * There is no member month calendar: the app builds its calendar from
     * the setup it already holds — open weekdays from the spaces list, the
     * closures and the booking window from the space details — and learns a
     * day's slots, and whether it is full, from the day endpoint.
     */
    public function test_there_is_no_member_month_calendar(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability?from=2026-08-17&to=2026-08-23")
            ->assertNotFound();
    }

    /**
     * The desk's hours card comes with its calendar, once — not with every
     * day's slots, which are the member's response exactly.
     */
    public function test_the_branch_hours_card_carries_the_weekday_range_and_a_derived_timezone_label(): void
    {
        $this->actingAsOperations();
        $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17');

        $response->assertOk()
            ->assertJsonPath('branch_hours.days_label', 'Sunday – Thursday');

        // Derived from the zone at request time, never hardcoded — Damascus
        // observes DST, so a fixed "GMT+3" would be wrong half the year.
        $this->assertStringStartsWith('GMT+', $response->json('branch_hours.timezone_label'));
    }

    public function test_a_closing_exception_returns_a_closed_banner_carrying_its_reason(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-08-19',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'Public holiday',
        ]);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-19");

        $response->assertOk()
            ->assertJsonPath('data.state', 'closed')
            ->assertJsonPath('data.slots', [])
            ->assertJsonPath('data.closed_banner.reason', 'Public holiday');
        
        $this->assertStringContainsString('closed on this date', $response->json('data.closed_banner.text'));
    }

    /**
     * The desk's hours card and the member's space details list the same
     * closures: the desk with each reason, the member as the dates alone.
     */
    public function test_the_desk_and_the_member_see_the_same_upcoming_closures(): void
    {
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-10-06',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'Public holiday',
        ]);

        $this->actingAsOperations();
        $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertOk()
            ->assertJsonPath('branch_hours.upcoming_closures', [['date' => '2026-10-06', 'reason' => 'Public holiday']]);

        $this->actingAsMember();
        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}")
            ->assertOk()
            ->assertJsonPath('data.booking.upcoming_closures', ['2026-10-06T00:00:00']);
    }

    public function test_a_weekday_with_no_schedule_is_closed_with_no_reason_to_give(): void
    {
        // Saturday has no BusinessHour rows at all. That is closed, but
        // there is no exception row to explain it — the distinction the
        // BusinessHoursService docblock calls out.
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-22");

        $response->assertOk()
            ->assertJsonPath('data.state', 'closed')
            ->assertJsonPath('data.closed_banner.reason', null);
    }

    public function test_a_day_whose_every_slot_is_taken_is_full_not_closed(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 8, 12 * 60);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('data.state', 'full')
            ->assertJsonPath('data.counts.available', 0)
            // A full day is open, so it must not show the closed banner.
            ->assertJsonPath('data.closed_banner', null);

        $this->assertGreaterThan(0, $response->json('data.counts.total'));
    }

    public function test_slots_for_a_day_return_available_booked_and_past_states(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 10);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('data.state', 'available')
            ->assertJsonPath('data.counts.total', 12)
            ->assertJsonPath('data.meta.granularity_minutes', 60);

        $byLabel = collect($response->json('data.slots'))->keyBy('start_time');

        $this->assertSame('booked', $byLabel['10:00 AM']['state']);
        $this->assertFalse($byLabel['10:00 AM']['is_available']);
        $this->assertSame('available', $byLabel['11:00 AM']['state']);
    }

    public function test_a_co_space_offers_a_slot_until_its_capacity_is_reached(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace([
            'space_type' => SpaceType::CoSpace,
            'is_lockable' => false,
            'capacity' => 2,
        ]);
        $this->book($space, 10);

        $slots = fn () => collect(
            $this->withHeader('lang', 'en')
                ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
                ->json('data.slots')
        )->firstWhere('start_time', '10:00 AM');

        $this->assertSame('available', $slots()['state'], 'One of two desks taken is still bookable.');

        $this->book($space, 10);

        // Every desk taken: simply `booked`, to a member and to the desk.
        $this->assertSame('booked', $slots()['state']);
        $this->assertFalse($slots()['is_available']);

        $this->actingAsOperations();
        $adminSlot = collect(
            $this->withHeader('lang', 'en')
                ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
                ->json('data.slots')
        )->firstWhere('start_time', '10:00 AM');
        $this->assertSame('booked', $adminSlot['state']);
    }

    /**
     * A member is sent available and booked slots only. One whose time to be
     * booked has gone — already started, or inside the minimum notice — is
     * left out, so today's list starts at the first start still bookable.
     */
    public function test_a_member_is_sent_only_slots_still_ahead_of_the_notice(): void
    {
        app(SettingService::class)->set('booking.min_notice_minutes', 120);
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 14);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk();

        $states = collect($response->json('data.slots'))->pluck('state', 'start_time');

        // Now is 08:00: 08:00 and 09:00 are inside the two-hour notice.
        $this->assertSame('10:00 AM', $states->keys()->first());
        $this->assertSame('available', $states['10:00 AM']);
        $this->assertSame('booked', $states['02:00 PM']);
        $this->assertEqualsCanonicalizing(['available', 'booked'], $states->unique()->values()->all());

        $this->assertSame(
            ['available' => 9, 'booked' => 1, 'total' => 10],
            $response->json('data.counts'),
        );
    }

    /**
     * Past the day's middle, the morning is simply not there: at 14:00 with
     * an hour's notice the first slot sent starts at 15:00.
     */
    public function test_today_after_midday_starts_at_the_first_start_an_hour_ahead(): void
    {
        app(SettingService::class)->set('booking.min_notice_minutes', 60);
        Carbon::setTestNow(Carbon::parse('2026-08-17 14:00:00', 'Asia/Damascus'));
        $this->actingAsMember();
        $space = $this->openSpace();

        $slots = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json('data.slots');

        $this->assertSame('03:00 PM', $slots[0]['start_time']);
        $this->assertNotContains('past', array_column($slots, 'state'));
    }

    /**
     * The desk is sent the same: a cell whose time to be booked has gone —
     * started, or inside the minimum notice — is left out on the server, not
     * filtered by the dashboard.
     */
    public function test_the_desk_is_not_sent_the_cells_whose_time_has_gone_either(): void
    {
        app(SettingService::class)->set('booking.min_notice_minutes', 60);
        Carbon::setTestNow(Carbon::parse('2026-08-17 14:00:00', 'Asia/Damascus'));
        $space = $this->openSpace();
        $this->book($space, 16);

        $this->actingAsOperations();
        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk();

        $states = collect($response->json('data.slots'))->pluck('state', 'start_time');

        // 08:00–14:00 have started; 14:00 is inside the hour's notice.
        $this->assertSame('03:00 PM', $states->keys()->first());
        $this->assertSame('booked', $states['04:00 PM']);

        // Counted over what is sent.
        $this->assertSame(['available' => 4, 'booked' => 1, 'total' => 5], $response->json('data.counts'));
    }

    /**
     * The branch keeps different hours on different days. `hours`/`periods`
     * are today's alone, so on their own they read as "every day, 08:00–22:00";
     * `schedule` states each group of days with its own hours, grouped the
     * way the dashboard's business-hours page groups them.
     */
    public function test_the_hours_card_states_each_days_own_hours_when_they_differ(): void
    {
        $this->actingAsMember();
        $space = Space::factory()->room()->create(['hourly_rate_points' => 100]);
        $branchId = $space->building->branch_id;

        foreach ([
            [DayOfWeek::Saturday, '08:00', '22:00'],
            [DayOfWeek::Monday, '08:00', '22:00'],
            [DayOfWeek::Tuesday, '08:00', '22:00'],
            [DayOfWeek::Wednesday, '08:00', '22:00'],
            [DayOfWeek::Thursday, '08:00', '22:00'],
            [DayOfWeek::Sunday, '02:00', '20:00'],
            [DayOfWeek::Friday, '14:00', '22:00'],
        ] as [$day, $open, $close]) {
            BusinessHour::factory()->create([
                'branch_id' => $branchId,
                'day_of_week' => $day,
                'open_time' => $open,
                'close_time' => $close,
            ]);
        }

        // The branch's hours come once, with the spaces list.
        $en = $this->withHeader('lang', 'en')->getJson('/api/v1/member/spaces')->assertOk();

        $this->assertSame([
            [
                'days' => ['saturday', 'monday', 'tuesday', 'wednesday', 'thursday'],
                'days_label' => 'Saturday, Monday – Thursday',
                'periods' => [['open_time' => '08:00', 'close_time' => '22:00']],
                'hours' => '8:00 AM – 10:00 PM',
            ],
            [
                'days' => ['sunday'],
                'days_label' => 'Sunday',
                'periods' => [['open_time' => '02:00', 'close_time' => '20:00']],
                'hours' => '2:00 AM – 8:00 PM',
            ],
            [
                'days' => ['friday'],
                'days_label' => 'Friday',
                'periods' => [['open_time' => '14:00', 'close_time' => '22:00']],
                'hours' => '2:00 PM – 10:00 PM',
            ],
        ], $en->json('data.working_hours_banner.schedule'));

        // Every day is open: the week, from Saturday as the dashboard orders it.
        $en->assertJsonPath('data.working_hours_banner.days_label', 'Saturday – Friday')
            ->assertJsonPath('data.working_hours_banner.open_weekdays', [
                'saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday',
            ]);

        // Translated, with the Arabic separator.
        $this->withHeader('lang', 'ar')->getJson('/api/v1/member/spaces')
            ->assertOk()
            ->assertJsonPath('data.working_hours_banner.schedule.0.days_label', 'السبت، الاثنين – الخميس')
            ->assertJsonPath('data.working_hours_banner.schedule.1.days_label', 'الأحد')
            ->assertJsonPath('data.working_hours_banner.days_label', 'السبت – الجمعة');
    }

    public function test_the_availability_endpoints_are_closed_to_an_unauthenticated_caller(): void
    {
        $space = $this->openSpace();

        $this->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")->assertUnauthorized();
    }

    public function test_a_non_active_space_is_not_found(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['status' => OperationalStatus::Maintenance]);

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertNotFound();
    }

    /**
     * No response carries a legend any more — the app draws its own from the
     * slot states — so what stays localised here is the closed banner.
     */
    public function test_the_arabic_locale_returns_an_arabic_closed_banner_and_no_legend(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-08-19',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'عطلة رسمية',
        ]);

        $response = $this->withHeader('lang', 'ar')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-19");

        $response->assertOk()
            ->assertJsonMissingPath('data.legend')
            ->assertJsonPath('data.closed_banner.reason', 'عطلة رسمية');

        $this->assertStringContainsString('مغلق', $response->json('data.closed_banner.text'));

        // Nor the desk's slot grid.
        $this->actingAsOperations();
        $this->withHeader('lang', 'ar')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->assertJsonMissingPath('data.legend');
    }

    public function test_a_space_requiring_approval_says_so_in_its_rules(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace(['requires_approval' => true]);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}");

        $response->assertOk()
            ->assertJsonPath('data.booking.rules.requires_approval', true)
            ->assertJsonPath('data.booking.rules.max_selectable_dates', 10);

        $this->assertNotNull($response->json('data.booking.rules.approval_notice'));
        // No ready-made note: the app words the minimum from min_duration_minutes.
        $this->assertArrayNotHasKey('min_duration_note', $response->json('data.booking.rules'));
    }

    /**
     * Reception books through the same BookingCreationService a member does,
     * so the desk reads the same day as the member: one object, the same
     * lean slots, the same two states. The setup — the minimum length, the
     * booking window, the hours — comes once with the calendar, as the
     * member's comes once with the space details.
     */
    public function test_operations_reads_the_same_lean_day_as_a_member(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $this->book($space, 14);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->assertJsonPath('data.meta.granularity_minutes', 60)
            ->assertJsonPath('data.meta.timezone', 'Asia/Damascus');

        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(
            ['date', 'state', 'closed_banner', 'slots', 'counts', 'meta'],
            array_keys($response->json('data')),
        );

        $booked = collect($response->json('data.slots'))->firstWhere('start_time', '02:00 PM');
        $this->assertSame(
            ['start_time' => '02:00 PM', 'end_time' => '03:00 PM', 'state' => 'booked', 'is_available' => false],
            $booked,
        );
    }

    public function test_the_admin_and_member_slot_grids_are_byte_identical(): void
    {
        $space = $this->openSpace();
        $this->book($space, 14);

        $this->actingAsMember();
        $asMember = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json();

        $this->actingAsOperations();
        $asOperations = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json();

        // No envelope of its own, no extra field: the very same body.
        $this->assertSame($asMember, $asOperations);
    }

    /**
     * The admin routes sit inside the file's own admin|operations group. A
     * member reaching them would not be a leak -- the same data is on their
     * own route -- but it would mean the group stopped applying, which is
     * worth catching here rather than in whatever gets added next to it.
     */
    public function test_a_member_cannot_reach_the_admin_availability_routes(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $this->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // A booking is a run of cells: no duration, no quote
    // ------------------------------------------------------------------

    /**
     * A member slot carries only what is its own. The date is `data.date`;
     * repeating it on every slot, or as full instants, added nothing the app
     * did not already hold.
     */
    public function test_a_member_slot_carries_only_its_own_fields(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $slot = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json('data.slots.0');

        $this->assertSame(['start_time', 'end_time', 'state', 'is_available'], array_keys($slot));
    }

    /**
     * One format, shown and sent alike: `start_time` / `end_time` as 12-hour
     * `hh:mm AM|PM`, AM/PM in Latin letters whatever the language, so the
     * value always parses back. There is no `label` in another format beside
     * them — the app and the dashboard show the very value they post back, so
     * neither converts.
     */
    /**
     * The shown times go back as they are: a booking posted as
     * "{date} {start_time}" / "{date} {end_time}" in the 12-hour form lands
     * on exactly that window.
     */
    public function test_a_booking_posted_with_the_shown_12_hour_times_lands_on_that_window(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $slot = $this->withHeader('lang', 'ar')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json('data.slots.6');
        $this->assertSame('02:00 PM', $slot['start_time']);

        $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/bookings', [
                'payment_method' => 'cash',
                'space_id' => $space->id,
                'start_at' => "2026-08-17 {$slot['start_time']}",
                'end_at' => "2026-08-17 {$slot['end_time']}",
            ])
            ->assertCreated();

        $booking = Booking::query()->where('space_id', $space->id)->sole();
        $this->assertSame('2026-08-17 14:00', $booking->start_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
        $this->assertSame('2026-08-17 15:00', $booking->end_at->setTimezone('Asia/Damascus')->format('Y-m-d H:i'));
    }

    public function test_a_slot_has_one_time_format_on_both_surfaces(): void
    {
        $space = $this->openSpace();

        $this->actingAsMember();
        $member = $this->withHeader('lang', 'ar')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json('data.slots.5');

        $this->actingAsOperations();
        $desk = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json('data.slots.5');

        foreach ([$member, $desk] as $slot) {
            $this->assertArrayNotHasKey('label', $slot);
            $this->assertSame('01:00 PM', $slot['start_time']);
            $this->assertSame('02:00 PM', $slot['end_time']);
        }
    }

    /**
     * The member's slots are the space's own cells; the member picks a start
     * cell and an end cell. So there is no length and no price to state —
     * the app prices the run from the details' `hourly_rate_points`.
     */
    public function test_member_slots_are_the_spaces_own_cells_with_no_length_or_price(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk();

        $this->assertSame(['granularity_minutes', 'timezone'], array_keys($response->json('data.meta')));
        $this->assertSame('08:00 AM', $response->json('data.slots.0.start_time'));
        $this->assertSame('09:00 AM', $response->json('data.slots.0.end_time'));
    }

    /**
     * Neither the member app nor the desk takes a duration: sent anyway, it
     * changes nothing — the day is still the space's own cells. Neither has
     * a quote either.
     */
    public function test_there_is_no_duration_and_no_quote(): void
    {
        $space = $this->openSpace();
        $this->book($space, 10);

        $this->actingAsMember();
        $member = fn (string $query) => $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17{$query}")
            ->assertOk()
            ->json('data');
        $this->assertSame($member(''), $member('&duration=120'));
        $this->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00")
            ->assertNotFound();

        $this->actingAsOperations();
        $desk = fn (string $query) => $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17{$query}")
            ->assertOk()
            ->json('data');
        $this->assertSame($desk(''), $desk('&duration=120'));
        $this->assertSame(['granularity_minutes', 'timezone'], array_keys($desk('')['meta']));
        $this->assertArrayNotHasKey('rules', $desk(''));
        $this->getJson("/api/v1/admin/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00")
            ->assertNotFound();
    }

    /**
     * With 30-minute cells and the 60-minute minimum, the half hour before a
     * booking is a free cell on both surfaces: it can end a run that starts
     * half an hour earlier, even though it cannot start one.
     */
    public function test_the_cell_before_a_booking_is_free_for_the_member_and_the_desk(): void
    {
        $space = $this->openSpace(['slot_granularity_minutes' => 30]);
        $this->book($space, 11);

        $this->actingAsMember();
        $member = collect($this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json('data.slots'))->pluck('state', 'start_time');
        $this->assertSame('available', $member['10:30 AM']);
        $this->assertSame('booked', $member['11:00 AM']);

        $this->actingAsOperations();
        $desk = collect($this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json('data.slots'))->pluck('state', 'start_time');
        $this->assertSame('available', $desk['10:30 AM']);
        $this->assertSame('booked', $desk['11:00 AM']);
    }

    public function test_days_beyond_the_advance_horizon_are_out_of_range(): void
    {
        app(SettingService::class)->set('booking.max_days_ahead', 7);
        $this->actingAsMember();
        $space = $this->openSpace();

        $day = fn (string $date) => $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date={$date}")
            ->assertOk();

        $day('2026-08-24')->assertJsonPath('data.state', 'available');
        $day('2026-08-25')
            ->assertJsonPath('data.state', 'out_of_range')
            ->assertJsonPath('data.closed_banner', null);
    }

    public function test_the_rules_block_carries_the_minimum_length_and_the_booking_window(): void
    {
        app(SettingService::class)->set('booking.min_notice_minutes', 30);
        app(SettingService::class)->set('booking.max_days_ahead', 30);
        $this->actingAsMember();
        $space = $this->openSpace();

        // The member app reads the rules once, from the space details.
        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}")
            ->assertOk()
            ->assertJsonPath('data.booking.rules.min_duration_minutes', 60)
            // No ceiling on a booking's length.
            ->assertJsonMissingPath('data.booking.rules.max_duration_minutes')
            ->assertJsonPath('data.booking.rules.min_notice_minutes', 30)
            ->assertJsonPath('data.booking.rules.max_days_ahead', 30)
            // No ladder of lengths: the member picks a start and an end cell,
            // at least min_duration_minutes long.
            ->assertJsonMissingPath('data.booking.rules.duration_options_minutes');
    }

    /**
     * The setup the member app reads once from the space details agrees with
     * the one the desk reads once from its calendar: the same minimum length,
     * booking window and closures.
     */
    public function test_the_details_booking_setup_matches_the_desks_calendar_setup(): void
    {
        app(SettingService::class)->set('booking.min_notice_minutes', 30);
        app(SettingService::class)->set('booking.max_days_ahead', 30);
        $space = $this->openSpace();
        BusinessHourException::factory()->create([
            'branch_id' => $space->building->branch_id,
            'date' => '2026-09-01',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
        ]);

        $this->actingAsMember();
        $setup = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}")
            ->assertOk()
            ->json('data.booking');

        $this->actingAsOperations();
        $calendar = $this->withHeader('lang', 'en')
            ->getJson('/api/v1/admin/calendar?view=day&date=2026-08-17')
            ->assertOk()
            ->json();

        $this->assertSame(
            array_intersect_key($setup['rules'], $calendar['booking_window']),
            $calendar['booking_window'],
        );
        // The desk's card keeps each closure's reason; the member calendar
        // needs only the dates, as local-midnight date-times. Same closures
        // either way.
        $this->assertSame(
            array_map(fn (string $date) => "{$date}T00:00:00", array_column($calendar['branch_hours']['upcoming_closures'], 'date')),
            $setup['upcoming_closures'],
        );
    }
}
