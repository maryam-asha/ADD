<?php

namespace Tests\Feature\Booking;

use App\Domain\Booking\Enums\SlotState;
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
     * The admin slots body, reduced the way the member route presents it:
     * the slot list under `slots` rather than `data`, `date` as the bare date
     * string rather than the day block, and without the booking setup
     * (`branch_hours`, `rules`, `legend`), which the member app gets once
     * from the space details. Every other key keeps its position.
     *
     * @param  array<string, mixed>  $adminBody
     * @return array<string, mixed>
     */
    private function inMemberShape(array $adminBody): array
    {
        $shaped = [];

        foreach ($adminBody as $key => $value) {
            if (in_array($key, ['branch_hours', 'rules'], true)) {
                continue;
            }

            if ($key === 'date') {
                $value = $value['date'];
            }

            // A member slot keeps only its own fields, its state folded into
            // available or booked; one that folds into past is left out.
            if ($key === 'data') {
                $fields = ['start_time', 'end_time', 'label', 'state', 'is_available'];
                $value = array_map(
                    fn (array $slot) => array_combine($fields, array_map(
                        fn (string $field) => $field === 'state'
                            ? SlotState::from($slot['state'])->forMember()->value
                            : $slot[$field],
                        $fields,
                    )),
                    $value,
                );
                $value = array_values(array_filter($value, fn (array $slot) => $slot['state'] !== 'past'));
            }

            // And counted in those two; the past ones are not sent, so they
            // are not counted either.
            if ($key === 'counts') {
                $folded = ['available' => 0, 'booked' => 0, 'past' => 0];

                foreach ($value as $state => $count) {
                    if ($state !== 'total') {
                        $folded[SlotState::from($state)->forMember()->value] += $count;
                    }
                }

                $value = [
                    'available' => $folded['available'],
                    'booked' => $folded['booked'],
                    'total' => $value['total'] - $folded['past'],
                ];
            }

            // No length and no price: the member's slots are plain cells.
            if ($key === 'meta') {
                $value = array_diff_key($value, ['duration_minutes' => true, 'price' => true]);
            }

            $shaped[$key === 'data' ? 'slots' : $key] = $value;
        }

        return $shaped;
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

    public function test_the_branch_hours_card_carries_the_weekday_range_and_a_derived_timezone_label(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('branch_hours.hours', '8:00 AM – 8:00 PM')
            ->assertJsonPath('branch_hours.days_label', 'Sunday – Thursday')
            ->assertJsonPath('branch_hours.open_time', '08:00');

        // Derived from the zone at request time, never hardcoded — Damascus
        // observes DST, so a fixed "GMT+3" would be wrong half the year.
        $this->assertStringStartsWith('GMT+', $response->json('branch_hours.timezone_label'));
        $this->assertStringContainsString(
            $response->json('branch_hours.timezone_label'),
            $response->json('branch_hours.timezone_note')
        );
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
            ->assertJsonPath('data.closed_banner.reason', 'Public holiday')
            ->assertJsonPath('data.closed_banner.severity', 'error');

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
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
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

        $byLabel = collect($response->json('data.slots'))->keyBy('label');

        $this->assertSame('booked', $byLabel['10:00 AM']['state']);
        $this->assertFalse($byLabel['10:00 AM']['is_available']);
        $this->assertSame('available', $byLabel['11:00 AM']['state']);
    }

    /**
     * The whole point of one shared engine: the sentence on the desk's
     * greyed-out chip and the sentence in the 422 are the same sentence. If
     * these ever diverge, the calendar is lying about why. (The member slots
     * carry no reason — the app greys a chip by its `state` — so this is
     * pinned on the admin grid, which still shows one.)
     */
    public function test_a_booked_slots_reason_is_the_exact_message_the_post_endpoint_returns(): void
    {
        $space = $this->openSpace();
        $this->book($space, 14);

        $this->actingAsOperations();
        $chipReason = collect(
            $this->withHeader('lang', 'en')
                ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
                ->json('data')
        )->firstWhere('label', '02:00 PM')['reason'];

        $this->actingAsMember();
        $postMessage = $this->withHeader('lang', 'en')
            ->postJson('/api/v1/member/bookings', [
                'space_id' => $space->id,
                'start_at' => '2026-08-17 14:00',
                'end_at' => '2026-08-17 15:00',
            ])->assertStatus(422)->json('message');

        $this->assertSame($postMessage, $chipReason);
    }

    public function test_the_quote_returns_the_configured_duration_ladder_with_end_times_and_points(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('hourly_rate_points', 100)
            ->assertJsonCount(4, 'durations')
            ->assertJsonPath('durations.0.minutes', 60)
            ->assertJsonPath('durations.0.label', '1h')
            ->assertJsonPath('durations.0.points', 100)
            ->assertJsonPath('durations.0.end_time', '11:00 AM')
            ->assertJsonPath('durations.1.label', '1.5h')
            ->assertJsonPath('durations.1.points', 150)
            // The fourth chip is 3h, matching the design — derived from
            // min+granularity it would have been 2.5h.
            ->assertJsonPath('durations.3.minutes', 180)
            ->assertJsonPath('durations.3.label', '3h');
    }

    public function test_a_duration_running_past_closing_time_is_offered_but_marked_unavailable(): void
    {
        // The dashboard greys the chip out rather than hiding it, so the
        // desk can see the option exists and why it cannot be taken.
        $this->actingAsOperations();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/quote?start_at=2026-08-17 19:00");

        $response->assertOk()
            ->assertJsonPath('durations.0.available', true)
            ->assertJsonPath('durations.1.available', false);

        $this->assertNotNull($response->json('durations.1.reason'));
    }

    public function test_a_duration_overlapping_an_existing_booking_is_marked_unavailable(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $this->book($space, 11);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('durations.0.available', true)
            ->assertJsonPath('durations.1.available', false);
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
        )->firstWhere('label', '10:00 AM');

        $this->assertSame('available', $slots()['state'], 'One of two desks taken is still bookable.');

        $this->book($space, 10);

        // Every desk taken. To a member that is simply `booked` — the desk's
        // grid still says `at_capacity`, which is what an operator needs.
        $this->assertSame('booked', $slots()['state']);
        $this->assertFalse($slots()['is_available']);

        $this->actingAsOperations();
        $adminSlot = collect(
            $this->withHeader('lang', 'en')
                ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
                ->json('data')
        )->firstWhere('label', '10:00 AM');
        $this->assertSame('at_capacity', $adminSlot['state']);
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
        $this->assertSame('10:00', $states->keys()->first());
        $this->assertSame('available', $states['10:00']);
        $this->assertSame('booked', $states['14:00']);
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

        $this->assertSame('15:00', $slots[0]['start_time']);
        $this->assertNotContains('past', array_column($slots, 'state'));
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
            ->assertJsonMissingPath('legend');
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
     * so the desk has to be able to see the same slots. These assertions are
     * deliberately identical in shape to the member ones above: if the two
     * surfaces ever diverge, one of the pairs fails.
     */
    public function test_operations_reads_the_same_slot_grid_as_a_member(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $this->book($space, 14);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17");

        $response->assertOk()
            ->assertJsonPath('meta.granularity_minutes', 60)
            ->assertJsonPath('meta.timezone', 'Asia/Damascus');

        $slots = collect($response->json('data'));
        $booked = $slots->firstWhere('start_time', '14:00');

        $this->assertSame('booked', $booked['state']);
        $this->assertFalse($booked['is_available']);
        $this->assertNotNull($booked['reason'], 'a refused slot must say why');
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

        // The member route wraps the same payload in `data` and names the
        // slot list `slots`; that envelope is the only permitted difference.
        $this->assertSame($asMember['data'], $this->inMemberShape($asOperations));
    }

    public function test_operations_reads_the_duration_ladder_with_each_option_priced(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00");

        $response->assertOk()
            ->assertJsonPath('meta.granularity_minutes', 60)
            ->assertJsonPath('durations.0.minutes', 60);

        // 90 minutes against 60-minute granularity: returned disabled WITH a
        // reason, never hidden, so the desk can say why it is refused.
        $ninety = collect($response->json('durations'))->firstWhere('minutes', 90);
        $this->assertNotNull($ninety, '90 minutes must be listed even when it cannot be booked');
        $this->assertFalse($ninety['available']);
        $this->assertNotNull($ninety['reason']);
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
        $this->getJson("/api/v1/admin/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00")
            ->assertForbidden();
    }

    // ------------------------------------------------------------------
    // Duration first: the app picks a length, then sees where it fits
    // ------------------------------------------------------------------

    public function test_slots_for_a_duration_are_whole_bookable_windows_priced_once(): void
    {
        $this->actingAsOperations();
        $space = $this->openSpace();
        $this->book($space, 10);

        $response = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17&duration=120");

        $response->assertOk()
            ->assertJsonPath('meta.duration_minutes', 120)
            // Every slot of one duration costs the same at a flat rate, so
            // the price is stated once rather than repeated on each slot.
            ->assertJsonPath('meta.price.points', 200)
            ->assertJsonPath('data.0.start_time', '08:00')
            ->assertJsonPath('data.0.end_time', '10:00')
            ->assertJsonPath('data.0.is_available', true)
            // 09:00–11:00 runs into the 10:00 booking.
            ->assertJsonPath('data.1.start_time', '09:00')
            ->assertJsonPath('data.1.state', 'booked')
            ->assertJsonPath('data.1.is_available', false);

        // The last start that still ends by 20:00.
        $this->assertSame('18:00', collect($response->json('data'))->last()['start_time']);
    }

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

        $this->assertSame(['start_time', 'end_time', 'label', 'state', 'is_available'], array_keys($slot));
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
        $this->assertSame('08:00', $response->json('data.slots.0.start_time'));
        $this->assertSame('09:00', $response->json('data.slots.0.end_time'));
    }

    public function test_a_duration_this_space_does_not_accept_is_a_validation_error(): void
    {
        $this->actingAsOperations();
        // 60-minute granularity: 90 minutes is not a legal length here.
        $space = $this->openSpace();

        $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17&duration=90")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['duration']);
    }

    /**
     * `duration` is not a member parameter: sent anyway, it changes nothing
     * — the day is still the space's own cells. The quote — the ladder of
     * lengths from a chosen start — is the desk's alone.
     */
    public function test_the_member_endpoints_take_no_duration(): void
    {
        $this->actingAsMember();
        $space = $this->openSpace();
        $this->book($space, 10);

        $slots = fn (string $query) => $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}/availability/slots?date=2026-08-17{$query}")
            ->assertOk()
            ->json('data');
        $this->assertSame($slots(''), $slots('&duration=120'));

        $this->getJson("/api/v1/member/spaces/{$space->id}/availability/quote?start_at=2026-08-17 10:00")
            ->assertNotFound();
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
     * The setup the member app reads once from the space details is the same
     * one the desk's slot grid carries for today — one builder, so the member
     * calendar and the desk cannot disagree about hours, rules or legend.
     */
    public function test_the_details_booking_setup_matches_the_setup_on_the_admin_slots_for_today(): void
    {
        $space = $this->openSpace();

        $this->actingAsMember();
        $setup = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/member/spaces/{$space->id}")
            ->assertOk()
            ->json('data.booking');

        $this->actingAsOperations();
        $admin = $this->withHeader('lang', 'en')
            ->getJson("/api/v1/admin/spaces/{$space->id}/availability/slots?date=2026-08-17")
            ->assertOk()
            ->json();

        // The same rules, less the desk's duration ladder.
        $this->assertSame(array_diff_key($admin['rules'], ['duration_options_minutes' => true]), $setup['rules']);
        // The desk's card keeps each closure's reason; the member calendar
        // needs only the dates, as local-midnight date-times. Same closures
        // either way.
        $this->assertSame(
            array_map(fn (string $date) => "{$date}T00:00:00", array_column($admin['branch_hours']['upcoming_closures'], 'date')),
            $setup['upcoming_closures'],
        );
    }
}
