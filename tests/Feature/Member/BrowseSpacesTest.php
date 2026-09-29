<?php

namespace Tests\Feature\Member;

use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SettingSeeder;
use Database\Seeders\SpaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BrowseSpacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(SettingSeeder::class);
        $this->seed(CurrencySeeder::class);
        $this->seed(SpaceSeeder::class);
        Carbon::setTestNow(Carbon::parse('2025-05-16 08:00:00', 'Asia/Damascus'));
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

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/member/spaces');
        $response->assertUnauthorized();
    }

    public function test_member_can_browse_spaces_and_matches_design_payload(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/spaces');

        $response->assertOk();
        // `data` holds the banner and the list as `spaces`; the paging `meta`
        // sits beside it, describing the response rather than the branch.
        $this->assertSame(['working_hours_banner', 'spaces'], array_keys($response->json('data')));
        $this->assertSame(['data', 'meta'], array_keys($response->json()));

        $response->assertJsonStructure(['data' => [
            'working_hours_banner' => [
                'text',
                'hours',
                'open_time',
                'close_time',
                'is_open',
            ],
            'spaces' => [
                '*' => [
                    'id',
                    'title',
                    'subtitle',
                    'badge',
                    'status',
                    'status_label',
                    'space_type',
                    'capacity',
                    'capacity_label',
                    'pricing' => [
                        'starting_from',
                        'hourly_rate',
                        'currency',
                        'currency_symbol',
                        'formatted',
                    ],
                    'amenities' => [
                        '*' => ['key', 'label', 'icon'],
                    ],
                    'main_image',
                ],
            ],
        ],
            'meta' => [
                'current_page',
                'from',
                'last_page',
                'per_page',
                'to',
                'total',
                'total_results',
                'space_type',
                'branch_id',
            ],
        ]);

        $response->assertJsonPath('data.working_hours_banner.hours', '8:00 AM – 10:00 PM');
        $response->assertJsonPath('data.working_hours_banner.is_open', true);
        $response->assertJsonPath('meta.total_results', 4);
    }

    /**
     * The branch's hours are the same for every space, so they come once,
     * with the list — the ordinary week (`schedule`, `open_weekdays`,
     * `days_label`) and today's periods — rather than repeated in each
     * space's details. The upcoming closures are not here: the booking
     * calendar is set up from the space details, so they sit there.
     */
    public function test_the_list_banner_carries_the_branchs_week(): void
    {
        $this->actingAsMember();
        $branchId = Space::query()->firstOrFail()->building->branch_id;
        BusinessHourException::factory()->create([
            'branch_id' => $branchId,
            'date' => '2025-05-20',
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
            'reason' => 'Public holiday',
        ]);

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/spaces')->assertOk();

        $response->assertJsonStructure(['data' => [
            'working_hours_banner' => [
                'text', 'hours', 'open_time', 'close_time', 'is_open',
                'title', 'periods', 'schedule', 'days_label', 'open_weekdays',
                'timezone', 'timezone_label', 'timezone_note',
            ],
        ]]);
        $response->assertJsonMissingPath('data.working_hours_banner.upcoming_closures');

        // The seeded branch keeps 08:00–22:00 every day: one group, the week.
        $this->assertSame([[
            'days' => ['saturday', 'sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'days_label' => 'Saturday – Friday',
            'periods' => [['open_time' => '08:00', 'close_time' => '22:00']],
            'hours' => '8:00 AM – 10:00 PM',
        ]], $response->json('data.working_hours_banner.schedule'));

        $response->assertJsonPath('data.working_hours_banner.days_label', 'Saturday – Friday')
            ->assertJsonPath('data.working_hours_banner.periods', [['open_time' => '08:00', 'close_time' => '22:00']])
            ->assertJsonPath('data.working_hours_banner.timezone', 'Asia/Damascus');
    }

    public function test_filter_by_space_type(): void
    {
        $this->actingAsMember();

        // 1. Filter by room / meeting_room
        // "lang: en" pins the locale explicitly: Space::name is now
        // translatable and the request defaults to Arabic (see
        // SetLocaleFromHeader) absent a header, unlike the hardcoded
        // English-only title this replaced.
        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/spaces?space_type=meeting_room');
        $response->assertOk();
        $response->assertJsonCount(1, 'data.spaces');
        $response->assertJsonPath('data.spaces.0.space_type', 'room');
        $response->assertJsonPath('data.spaces.0.title', 'Meeting Room A');

        // 2. Filter by hot_desk / co_space
        $responseCoSpace = $this->getJson('/api/v1/member/spaces?space_type=hot_desk');
        $responseCoSpace->assertOk();
        $responseCoSpace->assertJsonCount(1, 'data.spaces');
        $responseCoSpace->assertJsonPath('data.spaces.0.space_type', 'co_space');
        $responseCoSpace->assertJsonPath('data.spaces.0.badge', 'OPEN SPACE');

        // 3. Filter by event_hall
        $responseEvent = $this->getJson('/api/v1/member/spaces?space_type=event_hall');
        $responseEvent->assertOk();
        $responseEvent->assertJsonCount(1, 'data.spaces');
        $responseEvent->assertJsonPath('data.spaces.0.space_type', 'event_hall');
        $responseEvent->assertJsonPath('data.spaces.0.badge', 'EVENT HALL');
        $responseEvent->assertJsonMissingPath('data.spaces.0.booking_rules');
        $responseEvent->assertJsonMissingPath('categories');
    }

    public function test_filter_by_min_capacity(): void
    {
        $this->actingAsMember();

        // Capacity >= 50 should return only Event Hall (capacity: 80)
        $response = $this->getJson('/api/v1/member/spaces?min_capacity=50');
        $response->assertOk();
        $response->assertJsonCount(1, 'data.spaces');
        $response->assertJsonPath('data.spaces.0.space_type', 'event_hall');
    }

    public function test_space_card_omits_available_times_and_booking_rules(): void
    {
        $this->actingAsMember();

        $response = $this->getJson('/api/v1/member/spaces?space_type=room');
        $response->assertOk();

        $response->assertJsonMissingPath('data.spaces.0.available_times');
        $response->assertJsonMissingPath('data.spaces.0.booking_rules');
        $response->assertJsonMissingPath('categories');
    }

    public function test_spaces_can_be_paginated(): void
    {
        $this->actingAsMember();

        // Request 2 spaces per page
        $response = $this->getJson('/api/v1/member/spaces?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data.spaces');
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonPath('meta.total', 4);
        $response->assertJsonPath('meta.last_page', 2);
        $response->assertJsonPath('meta.current_page', 1);
        // A next page exists — asserted through meta, since member
        // responses no longer carry a links block.
        $this->assertLessThan($response->json('meta.last_page'), $response->json('meta.current_page'));
        $this->assertNull($response->json('links'));

        // Request second page
        $responsePage2 = $this->getJson('/api/v1/member/spaces?per_page=2&page=2');
        $responsePage2->assertOk();
        $responsePage2->assertJsonCount(2, 'data.spaces');
        $responsePage2->assertJsonPath('meta.current_page', 2);
        $this->assertGreaterThan(1, $responsePage2->json('meta.current_page'));
        $this->assertNull($responsePage2->json('links'));
    }

    public function test_member_can_view_space_details_matching_design(): void
    {
        $this->actingAsMember();

        $space = Space::query()->where('space_type', 'co_space')->firstOrFail();

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/spaces/{$space->id}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'id',
                'badge',
                'title',
                'subtitle',
                'description',
                'images',
                'quick_features' => [
                    '*' => ['key', 'label', 'icon'],
                ],
                'details' => [
                    'status',
                    'status_label',
                    'price' => [
                        'starting_from',
                        'hourly_rate',
                        'currency',
                        'currency_symbol',
                        'formatted',
                    ],
                    'capacity' => [
                        'count',
                        'formatted',
                    ],
                    'location' => [
                        'branch',
                        'building',
                        'floor',
                        'zone',
                        'formatted',
                    ],
                    'working_hours' => [
                        'formatted',
                        'open_time',
                        'close_time',
                        'is_open',
                    ],
                ],
                'amenities' => [
                    '*' => ['key', 'name', 'icon'],
                ],
                'cta' => [
                    'space_id',
                    'button_text',
                    'can_book',
                ],
            ],
        ]);

        $response->assertJsonPath('data.title', 'Hot Desk');
        $response->assertJsonPath('data.details.status', 'active');
        $response->assertJsonMissingPath('data.status');
        $response->assertJsonPath('data.badge', 'OPEN SPACE');
        $response->assertJsonPath('data.subtitle', 'Open Workspace');
        $response->assertJsonPath('data.details.price.formatted', '$3 / hour');
        $response->assertJsonPath('data.cta.button_text', 'Book This Space');
        $response->assertJsonPath('data.cta.can_book', true);
    }

    /**
     * What the booking screen needs about this space and that does not change
     * with the date or the duration arrives once, with the details: its
     * booking rules (and the duration chips) and the dates it must refuse.
     * No legend — the app draws its own. The branch's hours are not repeated
     * here — they are the same for every space and come with the list's
     * `working_hours_banner`. The slots endpoint then carries only what
     * changes.
     */
    public function test_space_details_carry_the_booking_setup_the_calendar_starts_from(): void
    {
        $this->actingAsMember();
        $space = Space::query()->where('space_type', 'co_space')->firstOrFail();
        foreach (['2025-06-10' => 'Later holiday', '2025-05-20' => 'Public holiday', '2025-05-10' => 'Already past'] as $date => $reason) {
            BusinessHourException::factory()->create([
                'branch_id' => $space->building->branch_id,
                'date' => $date,
                'is_closed' => true,
                'open_time' => null,
                'close_time' => null,
                'reason' => $reason,
            ]);
        }

        $response = $this->withHeader('lang', 'en')->getJson("/api/v1/member/spaces/{$space->id}");

        $response->assertOk()->assertJsonStructure([
            'data' => [
                'booking' => [
                    'rules' => ['granularity_minutes', 'min_duration_minutes', 'min_notice_minutes', 'max_days_ahead', 'max_selectable_dates'],
                    'upcoming_closures',
                ],
            ],
        ]);

        $this->assertSame(['rules', 'upcoming_closures'], array_keys($response->json('data.booking')));
        // The dates the calendar must refuse, from today on and in order, as
        // ISO-8601 date-times at local midnight with no offset: each parses
        // straight to a DateTime on that day (DateTime.parse in the app), and
        // without an offset no device timezone can move it to the day before.
        // Already-past closures are left out.
        $this->assertSame(['2025-05-20T00:00:00', '2025-06-10T00:00:00'], $response->json('data.booking.upcoming_closures'));
        $this->assertArrayNotHasKey('duration_options_minutes', $response->json('data.booking.rules'));

        // Neither says anything the app needs: the greyed-chip note is the
        // app's own copy, and the concurrency ceiling is internal.
        $response->assertJsonMissingPath('data.booking.rules.slots_note')
            ->assertJsonMissingPath('data.booking.rules.concurrency_ceiling');
    }

    /**
     * A space under maintenance or retired never reaches a member's list —
     * the start of the only path into booking. Details and the availability
     * endpoints 404 it too; this pins the list end of that, which had no test.
     */
    public function test_the_list_leaves_out_spaces_that_are_not_active(): void
    {
        $this->actingAsMember();
        $building = Space::query()->firstOrFail()->building_id;

        $maintenance = Space::factory()->maintenance()->create(['building_id' => $building]);
        $retired = Space::factory()->create(['building_id' => $building, 'status' => OperationalStatus::Retired]);

        $response = $this->getJson('/api/v1/member/spaces?per_page=100')->assertOk();

        $ids = collect($response->json('data.spaces'))->pluck('id');
        $this->assertNotContains($maintenance->id, $ids);
        $this->assertNotContains($retired->id, $ids);
        // Only the four seeded active spaces are counted.
        $response->assertJsonPath('meta.total', 4);
    }

    public function test_inactive_space_details_returns_404(): void
    {
        $this->actingAsMember();

        $space = Space::factory()->maintenance()->create();

        $response = $this->getJson("/api/v1/member/spaces/{$space->id}");
        $response->assertNotFound();
    }

    public function test_space_title_reflects_its_own_custom_name(): void
    {
        $this->actingAsMember();

        $meetingRoom = Space::query()->where('space_type', 'room')->firstOrFail();
        $meetingRoom->update([
            'name' => ['en' => 'Boardroom Nile', 'ar' => 'قاعة النيل'],
        ]);

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/spaces?space_type=meeting_room');
        $response->assertOk();
        $response->assertJsonPath('data.spaces.0.title', 'Boardroom Nile');

        $detailResponse = $this->withHeader('lang', 'en')->getJson("/api/v1/member/spaces/{$meetingRoom->id}");
        $detailResponse->assertOk();
        $detailResponse->assertJsonPath('data.title', 'Boardroom Nile');
    }

    public function test_two_same_type_spaces_show_distinct_titles(): void
    {
        $this->actingAsMember();

        $meetingRoom = Space::query()->where('space_type', 'room')->firstOrFail();
        Space::factory()->room()->create([
            'building_id' => $meetingRoom->building_id,
            'zone_id' => $meetingRoom->zone_id,
            'name' => ['en' => 'Boardroom Nile', 'ar' => 'قاعة النيل'],
        ]);

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/spaces?space_type=meeting_room');
        $response->assertOk();
        $response->assertJsonCount(2, 'data.spaces');

        $titles = collect($response->json('data.spaces'))->pluck('title')->all();
        $this->assertContains('Meeting Room A', $titles);
        $this->assertContains('Boardroom Nile', $titles);
    }

    public function test_amenities_merge_space_amenities_and_equipment_resources(): void
    {
        $this->actingAsMember();

        // Wi-Fi is sourced from the space's SpaceAmenity rows (the seeded
        // default co_space matrix), not a Resource row — a Hot Desk no
        // longer has any Resource row categorized as an amenity.
        $hotDesk = Space::query()->where('space_type', 'co_space')->firstOrFail();

        $response = $this->withHeader('lang', 'en')->getJson('/api/v1/member/spaces?space_type=hot_desk');
        $response->assertOk();
        $cardAmenity = collect($response->json('data.spaces.0.amenities'))->firstWhere('key', 'wifi');
        $this->assertNotNull($cardAmenity);
        $this->assertSame('wifi', $cardAmenity['icon']);
        $this->assertSame('Wi-Fi', $cardAmenity['label']);

        $detailResponse = $this->withHeader('lang', 'en')->getJson("/api/v1/member/spaces/{$hotDesk->id}");
        $detailResponse->assertOk();
        $detailAmenity = collect($detailResponse->json('data.amenities'))->firstWhere('key', 'wifi');
        $this->assertNotNull($detailAmenity);
        $this->assertSame('wifi', $detailAmenity['icon']);
        $this->assertSame('Wi-Fi', $detailAmenity['name']);

        // Equipment still merges in alongside amenities — Meeting Room A's
        // screen is a real, active Resource row, not a SpaceAmenity one.
        $meetingRoom = Space::query()->where('space_type', 'room')->firstOrFail();
        $screenResource = $meetingRoom->resources()->where('name', 'Presentation Screen')->firstOrFail();

        $roomResponse = $this->withHeader('lang', 'en')->getJson('/api/v1/member/spaces?space_type=meeting_room');
        $roomResponse->assertOk();
        $screenAmenity = collect($roomResponse->json('data.spaces.0.amenities'))->firstWhere('key', 'screen');
        $this->assertNotNull($screenAmenity);
        $this->assertSame($screenResource->name, $screenAmenity['label']);
        $this->assertSame('screen', $screenAmenity['icon']);

        // Meeting Room A also carries its own `ac` SpaceAmenity row (seeded
        // `room` matrix) — both sources are present on the same card.
        $acAmenity = collect($roomResponse->json('data.spaces.0.amenities'))->firstWhere('key', 'ac');
        $this->assertNotNull($acAmenity);
        $this->assertSame('snowflake', $acAmenity['icon']);
        $this->assertSame('Air Conditioning', $acAmenity['label']);
    }

    public function test_browse_returns_first_image_as_main_image_and_details_returns_all_images(): void
    {
        $this->actingAsMember();

        $space = Space::query()->where('space_type', 'co_space')->firstOrFail();
        $space->update([
            'images' => [
                'https://example.com/primary.jpg',
                'https://example.com/second.jpg',
                'https://example.com/third.jpg',
            ],
        ]);

        // 1. Browse list: returns main_image as first item, does not have images array
        $browseResponse = $this->getJson('/api/v1/member/spaces');
        $browseResponse->assertOk();
        $spaceCard = collect($browseResponse->json('data.spaces'))->firstWhere('id', $space->id);
        $this->assertNotNull($spaceCard);
        $this->assertSame('https://example.com/primary.jpg', $spaceCard['main_image']);
        $this->assertArrayNotHasKey('images', $spaceCard);

        // 2. Details view: returns all images together, without separating main_image
        $detailResponse = $this->getJson("/api/v1/member/spaces/{$space->id}");
        $detailResponse->assertOk();
        $detailData = $detailResponse->json('data');
        $this->assertSame([
            'https://example.com/primary.jpg',
            'https://example.com/second.jpg',
            'https://example.com/third.jpg',
        ], $detailData['images']);
        $this->assertArrayNotHasKey('main_image', $detailData);
    }
}
