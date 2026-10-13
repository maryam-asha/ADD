<?php

namespace Tests\Feature\Member;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventSession;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceAxis;
use App\Domain\Services\Models\ServiceType;
use Carbon\Carbon;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * GET member/home. The district runs on Asia/Damascus (+03:00): every
 * "now" below is written in UTC with its local time beside it.
 */
class MemberHomeTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Space $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        // 10:00 in the district.
        Carbon::setTestNow(Carbon::parse('2026-10-13 07:00:00', 'UTC'));

        $this->member = User::factory()->create(['name' => 'Omar Haddad']);
        $this->member->assignRole('member');
        Sanctum::actingAs($this->member, ['*']);

        $branch = Branch::factory()->create(['name' => ['en' => 'Main Branch', 'ar' => 'الفرع الرئيسي']]);
        $building = Building::factory()->create(['branch_id' => $branch->id]);
        $this->room = Space::factory()->room()->create([
            'building_id' => $building->id,
            'name' => ['en' => 'Meeting Room 03', 'ar' => 'قاعة الاجتماعات 03'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function home(string $lang = 'en')
    {
        return $this->withHeader('lang', $lang)->getJson('/api/v1/member/home')->assertOk();
    }

    public function test_a_member_with_nothing_going_on_gets_every_section_empty(): void
    {
        $this->home()->assertExactJson(['data' => [
            'greeting' => 'Good morning, Omar',
            'unread_notifications' => 0,
            'active_session' => null,
            'banners' => [],
            'today' => [],
            'explore' => [],
        ]]);
    }

    public function test_the_greeting_follows_the_district_clock_and_the_language(): void
    {
        // 20:00 in the district, 17:00 UTC: afternoon by the server's clock.
        Carbon::setTestNow(Carbon::parse('2026-10-13 17:00:00', 'UTC'));

        $this->home('ar')->assertJsonPath('data.greeting', 'مساء الخير، Omar');
    }

    public function test_it_counts_the_unread_notifications(): void
    {
        foreach ([null, null, now()] as $readAt) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'notifiable_type' => User::class,
                'notifiable_id' => $this->member->id,
                'data' => '{}',
                'read_at' => $readAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->home()->assertJsonPath('data.unread_notifications', 2);
    }

    public function test_a_running_walk_in_session_is_the_card_on_top(): void
    {
        $openSpace = Space::factory()->create([
            'building_id' => $this->room->building_id,
            'space_type' => SpaceType::CoSpace,
            'name' => ['en' => 'Open Space', 'ar' => 'المساحة المفتوحة'],
        ]);
        $session = WalkinSession::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $openSpace->id,
            'checked_in_at' => now()->subMinutes(85),
        ]);

        $this->home()->assertJsonPath('data.active_session', [
            'type' => 'walkin',
            'id' => $session->id,
            'space' => ['id' => $openSpace->id, 'name' => 'Open Space', 'type' => 'co_space'],
            'branch' => ['id' => $openSpace->building->branch_id, 'name' => 'Main Branch'],
            'checked_in_at' => '2026-10-13T08:35:00+03:00',
            'end_requested' => false,
        ]);
    }

    public function test_today_holds_the_bookings_still_ahead_today_in_the_district(): void
    {
        $later = Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->room->id,
            // 16:00 – 17:30 in the district.
            'start_at' => '2026-10-13 13:00:00',
            'end_at' => '2026-10-13 14:30:00',
        ]);
        $underWay = Booking::factory()->pending()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->room->id,
            'start_at' => '2026-10-13 06:00:00',
            'end_at' => '2026-10-13 08:00:00',
        ]);
        // Over already.
        Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->room->id,
            'start_at' => '2026-10-13 05:00:00',
            'end_at' => '2026-10-13 06:00:00',
        ]);
        // 00:30 tomorrow in the district, though still the 13th in UTC.
        Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->room->id,
            'start_at' => '2026-10-13 21:30:00',
            'end_at' => '2026-10-13 22:30:00',
        ]);
        Booking::factory()->cancelled()->create([
            'user_id' => $this->member->id,
            'space_id' => $this->room->id,
            'start_at' => '2026-10-13 11:00:00',
            'end_at' => '2026-10-13 12:00:00',
        ]);
        // Someone else's.
        Booking::factory()->create([
            'space_id' => $this->room->id,
            'start_at' => '2026-10-13 11:00:00',
            'end_at' => '2026-10-13 12:00:00',
        ]);

        $response = $this->home();

        $response->assertJsonCount(2, 'data.today');
        $response->assertJsonPath('data.today.0.id', $underWay->id);
        $response->assertJsonPath('data.today.0.status', BookingStatus::Pending->value);
        $response->assertJsonPath('data.today.1', [
            'kind' => 'booking',
            'id' => $later->id,
            'title' => 'Meeting Room 03',
            'space' => ['id' => $this->room->id, 'name' => 'Meeting Room 03', 'type' => 'room'],
            'branch' => ['id' => $this->room->building->branch_id, 'name' => 'Main Branch'],
            'image_url' => null,
            'start_at' => '2026-10-13T16:00:00+03:00',
            'end_at' => '2026-10-13T17:30:00+03:00',
            'date_label' => 'Today, October 13, 2026',
            'time_label' => '04:00 PM - 05:30 PM (1.5h)',
            'attendees_count' => null,
            'status' => 'confirmed',
            'status_label' => BookingStatus::Confirmed->label('en'),
        ]);
    }

    public function test_a_booking_that_is_the_running_session_is_not_listed_again(): void
    {
        $openSpace = Space::factory()->create(['building_id' => $this->room->building_id, 'space_type' => SpaceType::CoSpace]);
        $running = Booking::factory()->create([
            'user_id' => $this->member->id,
            'space_id' => $openSpace->id,
            'start_at' => '2026-10-13 06:00:00',
            'end_at' => '2026-10-13 09:00:00',
            'checked_in_at' => '2026-10-13 06:05:00',
        ]);

        $response = $this->home();

        $response->assertJsonPath('data.active_session.type', 'booking');
        $response->assertJsonPath('data.active_session.id', $running->id);
        $response->assertJsonPath('data.today', []);
    }

    public function test_today_holds_the_events_the_member_is_registered_for_by_todays_sitting(): void
    {
        $talk = Event::factory()->create([
            'title' => ['en' => 'Strategy Meeting', 'ar' => 'اجتماع الاستراتيجية'],
            'space_id' => $this->room->id,
            // 18:00 – 19:00 in the district.
            'start_at' => '2026-10-13 15:00:00',
            'end_at' => '2026-10-13 16:00:00',
        ]);
        EventAttendee::factory()->create(['event_id' => $talk->id, 'user_id' => $this->member->id]);
        EventAttendee::factory()->count(5)->create(['event_id' => $talk->id]);
        EventAttendee::factory()->rejected()->create(['event_id' => $talk->id]);

        // Three mornings; today's sitting is 09:00 – 12:00 in the district.
        $course = Event::factory()->create([
            'title' => ['en' => 'Course', 'ar' => 'دورة'],
            'start_at' => '2026-10-12 06:00:00',
            'end_at' => '2026-10-14 09:00:00',
        ]);
        foreach (['2026-10-12', '2026-10-13', '2026-10-14'] as $day) {
            EventSession::factory()->create(['event_id' => $course->id, 'start_at' => "{$day} 06:00:00", 'end_at' => "{$day} 09:00:00"]);
        }
        EventAttendee::factory()->pending()->create(['event_id' => $course->id, 'user_id' => $this->member->id]);

        // Its envelope spans today, but it sits on neither side of it.
        $gap = Event::factory()->create(['start_at' => '2026-10-12 06:00:00', 'end_at' => '2026-10-14 09:00:00']);
        foreach (['2026-10-12', '2026-10-14'] as $day) {
            EventSession::factory()->create(['event_id' => $gap->id, 'start_at' => "{$day} 06:00:00", 'end_at' => "{$day} 09:00:00"]);
        }
        EventAttendee::factory()->create(['event_id' => $gap->id, 'user_id' => $this->member->id]);

        $turnedDown = Event::factory()->create(['start_at' => '2026-10-13 12:00:00', 'end_at' => '2026-10-13 13:00:00']);
        EventAttendee::factory()->rejected()->create(['event_id' => $turnedDown->id, 'user_id' => $this->member->id]);

        $cancelled = Event::factory()->cancelled()->create(['start_at' => '2026-10-13 12:00:00', 'end_at' => '2026-10-13 13:00:00']);
        EventAttendee::factory()->create(['event_id' => $cancelled->id, 'user_id' => $this->member->id]);

        $response = $this->home();

        $response->assertJsonCount(2, 'data.today');
        $response->assertJsonPath('data.today.0.id', $course->id);
        $response->assertJsonPath('data.today.0.start_at', '2026-10-13T09:00:00+03:00');
        $response->assertJsonPath('data.today.0.end_at', '2026-10-13T12:00:00+03:00');
        $response->assertJsonPath('data.today.0.status', 'pending');
        $response->assertJsonPath('data.today.1.kind', 'event');
        $response->assertJsonPath('data.today.1.id', $talk->id);
        $response->assertJsonPath('data.today.1.title', 'Strategy Meeting');
        $response->assertJsonPath('data.today.1.space.name', 'Meeting Room 03');
        $response->assertJsonPath('data.today.1.branch.name', 'Main Branch');
        $response->assertJsonPath('data.today.1.attendees_count', 6);
        $response->assertJsonPath('data.today.1.status', 'approved');
        $response->assertJsonPath('data.today.1.status_label', 'Registered');
    }

    public function test_banners_are_the_live_announcements_placed_on_the_app_with_their_text(): void
    {
        $everywhere = Announcement::factory()->offer()->create([
            'sort_order' => 2,
            'subtitle' => ['en' => 'ADD Smart Ecosystem', 'ar' => 'منظومة ADD الذكية'],
            'title' => ['en' => 'Build. Connect. Grow.', 'ar' => 'ابن. تواصل. انمُ.'],
            'description' => ['en' => 'Everything your business needs.', 'ar' => 'كل ما يحتاجه عملك.'],
            'cta_label' => ['en' => 'Explore ADD', 'ar' => 'استكشف ADD'],
            'link_url' => 'https://example.com/explore',
        ]);
        $appOnly = Announcement::factory()->news()->onApp()->create(['sort_order' => 1]);
        Announcement::factory()->onKiosk()->create();
        Announcement::factory()->onApp()->expired()->create();
        Announcement::factory()->onApp()->create(['is_active' => false]);

        $response = $this->home('ar');

        $response->assertJsonCount(2, 'data.banners');
        $response->assertJsonPath('data.banners.0.id', $appOnly->id);
        $response->assertJsonPath('data.banners.0.title', null);
        $response->assertJsonPath('data.banners.1', [
            'id' => $everywhere->id,
            'type' => 'offer',
            'type_label' => 'عرض',
            'subtitle' => 'منظومة ADD الذكية',
            'title' => 'ابن. تواصل. انمُ.',
            'description' => 'كل ما يحتاجه عملك.',
            'cta_label' => 'استكشف ADD',
            'image_url' => $everywhere->image_url,
            'link_url' => 'https://example.com/explore',
        ]);
    }

    public function test_explore_is_the_services_put_on_the_home_screen_by_layer_then_order(): void
    {
        $core = ServiceAxis::create(['slug' => 'core', 'name' => ['en' => 'ADD Core', 'ar' => 'ADD Core'], 'color' => '#E5352B', 'order' => 1]);
        $experience = ServiceAxis::create(['slug' => 'experience', 'name' => ['en' => 'ADD Experience', 'ar' => 'ADD Experience'], 'color' => '#00C2D4', 'order' => 2]);

        $make = fn (ServiceAxis $axis, string $slug, int $order, array $overrides = []) => ServiceType::create([
            'service_axis_id' => $axis->id,
            'slug' => $slug,
            'name' => ['en' => Str::headline($slug), 'ar' => $slug],
            'description' => ['en' => "About {$slug}", 'ar' => $slug],
            'is_active' => true,
            'show_on_home' => true,
            'visibility' => 'member',
            'request_method' => 'internal_redirect',
            'request_config' => ['route' => 'spaces', 'params' => []],
            'order' => $order,
            ...$overrides,
        ]);

        $events = $make($experience, 'events', 1);
        $coSpace = $make($core, 'co-space', 2, ['image_url' => 'https://cdn.example.com/co-space.jpg']);
        $rooms = $make($core, 'rooms', 1);
        $make($core, 'general', 0, ['show_on_home' => false]);
        $make($core, 'incubate', 3, ['is_active' => false]);
        $make($core, 'website-only', 4, ['visibility' => 'public']);

        $response = $this->home();

        $response->assertJsonCount(3, 'data.explore');
        $response->assertJsonPath('data.explore.0.id', $rooms->id);
        $response->assertJsonPath('data.explore.1', [
            'id' => $coSpace->id,
            'slug' => 'co-space',
            'name' => 'Co Space',
            'description' => 'About co-space',
            'image_url' => 'https://cdn.example.com/co-space.jpg',
            'is_coming_soon' => false,
            'axis' => ['slug' => 'core', 'name' => 'ADD Core', 'color' => '#E5352B'],
        ]);
        $response->assertJsonPath('data.explore.2.id', $events->id);
    }

    public function test_an_operations_account_cannot_read_it(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $this->getJson('/api/v1/member/home')->assertForbidden();
    }
}
