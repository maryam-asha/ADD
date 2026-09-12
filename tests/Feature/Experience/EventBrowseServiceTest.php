<?php

namespace Tests\Feature\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventCategory;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventBrowseServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_can_browse_events_calendar_with_badges_and_seat_calculations(): void
    {
        $category = EventCategory::create([
            'name' => ['en' => 'Workshops', 'ar' => 'ورش عمل'],
            'slug' => 'workshops',
        ]);

        // 1. Free event with available seats
        $freeEvent = Event::create([
            'title' => ['en' => 'Startup Essentials', 'ar' => 'أساسيات الشركات الناشئة'],
            'category_id' => $category->id,
            'start_at' => now()->addDays(2)->setTime(10, 0),
            'end_at' => now()->addDays(2)->setTime(12, 0),
            'location' => 'Main Branch – Event Hall',
            'capacity' => 50,
            'is_free' => true,
            'price' => 0,
            'currency' => 'USD',
            'status' => 'published',
            'order' => 1,
        ]);

        // Add 32 attendees -> 18 seats left
        for ($i = 0; $i < 32; $i++) {
            EventAttendee::create([
                'event_id' => $freeEvent->id,
                'name' => "Attendee {$i}",
                'email' => "attendee{$i}@example.com",
            ]);
        }

        // 2. Paid event with $15 price
        $paidEvent = Event::create([
            'title' => ['en' => 'Brand Building Masterclass', 'ar' => 'بناء العلامة التجارية'],
            'category_id' => $category->id,
            'start_at' => now()->addDays(3)->setTime(14, 0),
            'end_at' => now()->addDays(3)->setTime(16, 0),
            'location' => 'Main Branch – Meeting Room',
            'capacity' => 30,
            'is_free' => false,
            'price' => 15.00,
            'currency' => 'USD',
            'status' => 'published',
            'order' => 2,
        ]);

        // 3. Full event (capacity 40, 40 attendees)
        $fullEvent = Event::create([
            'title' => ['en' => 'Networking Evening', 'ar' => 'أمسية تعارف'],
            'start_at' => now()->addDays(4)->setTime(18, 0),
            'end_at' => now()->addDays(4)->setTime(20, 0),
            'location' => 'ADD Café',
            'capacity' => 40,
            'is_free' => true,
            'price' => 0,
            'currency' => 'USD',
            'status' => 'published',
            'order' => 3,
        ]);

        for ($i = 0; $i < 40; $i++) {
            EventAttendee::create([
                'event_id' => $fullEvent->id,
                'name' => "Full Attendee {$i}",
                'email' => "full{$i}@example.com",
            ]);
        }

        // Test GET /api/v1/events with lang: en header
        $response = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/events');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'title',
                    'category',
                    'start_at',
                    'end_at',
                    'date' => ['month', 'day', 'weekday', 'formatted'],
                    'time' => ['start', 'end', 'range'],
                    'location',
                    'capacity',
                    'attendees_count',
                    'seats_left',
                    'seats_left_text',
                    'is_free',
                    'price',
                    'currency',
                    'badge' => ['type', 'label'],
                    'is_registered',
                    'can_register',
                ],
            ],
        ]);

        $data = collect($response->json('data'));

        // Check Free Event - string title, category has ONLY id and name
        $freeItem = $data->firstWhere('id', $freeEvent->id);
        $this->assertSame('Startup Essentials', $freeItem['title']);
        $this->assertEquals(['id' => $category->id, 'name' => 'Workshops'], $freeItem['category']);
        $this->assertEquals(50, $freeItem['capacity']);
        $this->assertEquals(32, $freeItem['attendees_count']);
        $this->assertEquals(18, $freeItem['seats_left']);
        $this->assertEquals('18 / 50 seats left', $freeItem['seats_left_text']);
        $this->assertEquals('free', $freeItem['badge']['type']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $freeItem['start_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $freeItem['end_at']);
        $this->assertTrue($freeItem['can_register']);

        // Check Paid Event
        $paidItem = $data->firstWhere('id', $paidEvent->id);
        $this->assertSame('Brand Building Masterclass', $paidItem['title']);
        $this->assertEquals('paid', $paidItem['badge']['type']);
        $this->assertEquals('$15', $paidItem['badge']['label']);

        // Check Full Event
        $fullItem = $data->firstWhere('id', $fullEvent->id);
        $this->assertEquals(0, $fullItem['seats_left']);
        $this->assertEquals('Full • 40/40 seats', $fullItem['seats_left_text']);
        $this->assertEquals('full', $fullItem['badge']['type']);
        $this->assertEquals('Full', $fullItem['badge']['label']);
        $this->assertFalse($fullItem['can_register']);

        // Check Arabic header
        $resAr = $this->withHeaders(['lang' => 'ar'])->getJson('/api/v1/events');
        $resAr->assertOk();
        $freeItemAr = collect($resAr->json('data'))->firstWhere('id', $freeEvent->id);
        $this->assertSame('أساسيات الشركات الناشئة', $freeItemAr['title']);
        $this->assertEquals(['id' => $category->id, 'name' => 'ورش عمل'], $freeItemAr['category']);
        $this->assertSame('مجاني', $freeItemAr['badge']['label']);
    }

    public function test_translatable_fields_return_string_based_on_header_lang_and_not_both_languages(): void
    {
        $category = EventCategory::create([
            'name' => ['en' => 'Technology', 'ar' => 'التكنولوجيا'],
            'slug' => 'tech',
        ]);

        $event = Event::create([
            'title' => ['en' => 'Flutter Development', 'ar' => 'تطوير تطبيقات فلاتر'],
            'description' => [
                'en' => 'Learn Flutter from scratch with real projects.',
                'ar' => 'تعلم فلاتر من الصفر بمشاريع حقيقية.',
            ],
            'category_id' => $category->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'status' => 'published',
        ]);

        // 1. English Header
        $resEn = $this->withHeaders(['lang' => 'en'])->getJson("/api/v1/events/{$event->id}");
        $resEn->assertOk();
        $this->assertIsString($resEn->json('data.title'));
        $this->assertEquals('Flutter Development', $resEn->json('data.title'));
        $this->assertIsString($resEn->json('data.description'));
        $this->assertEquals('Learn Flutter from scratch with real projects.', $resEn->json('data.description'));
        $this->assertEquals(['id' => $category->id, 'name' => 'Technology'], $resEn->json('data.category'));

        // 2. Arabic Header
        $resAr = $this->withHeaders(['lang' => 'ar'])->getJson("/api/v1/events/{$event->id}");
        $resAr->assertOk();
        $this->assertIsString($resAr->json('data.title'));
        $this->assertEquals('تطوير تطبيقات فلاتر', $resAr->json('data.title'));
        $this->assertIsString($resAr->json('data.description'));
        $this->assertEquals('تعلم فلاتر من الصفر بمشاريع حقيقية.', $resAr->json('data.description'));
        $this->assertEquals(['id' => $category->id, 'name' => 'التكنولوجيا'], $resAr->json('data.category'));
    }

    public function test_can_fetch_categories_separately_for_app(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        EventCategory::create([
            'name' => ['en' => 'Entrepreneurship', 'ar' => 'ريادة الأعمال'],
            'slug' => 'entrepreneurship',
            'icon' => 'carbon:rocket',
            'color' => '#10B981',
            'order' => 1,
            'is_active' => true,
        ]);

        EventCategory::create([
            'name' => ['en' => 'Design & UX', 'ar' => 'التصميم وتجربة المستخدم'],
            'slug' => 'design-ux',
            'icon' => 'carbon:paint-brush',
            'color' => '#3B82F6',
            'order' => 2,
            'is_active' => true,
        ]);

        EventCategory::create([
            'name' => ['en' => 'Inactive Category', 'ar' => 'فئة معطلة'],
            'slug' => 'inactive',
            'order' => 3,
            'is_active' => false,
        ]);

        // 1. Member endpoint with English header
        $resEn = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/member/events/categories');
        $resEn->assertOk();
        $resEn->assertJsonCount(2, 'data');
        $this->assertEquals(['id' => $resEn->json('data.0.id'), 'name' => 'Entrepreneurship'], $resEn->json('data.0'));
        $this->assertEquals(['id' => $resEn->json('data.1.id'), 'name' => 'Design & UX'], $resEn->json('data.1'));

        // 2. Member endpoint with Arabic header
        $resAr = $this->withHeaders(['lang' => 'ar'])->getJson('/api/v1/member/events/categories');
        $resAr->assertOk();
        $this->assertEquals(['id' => $resAr->json('data.0.id'), 'name' => 'ريادة الأعمال'], $resAr->json('data.0'));
        $this->assertEquals(['id' => $resAr->json('data.1.id'), 'name' => 'التصميم وتجربة المستخدم'], $resAr->json('data.1'));

        // 3. Alternate route /member/event-categories
        $resAlt = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/member/event-categories');
        $resAlt->assertOk();
        $resAlt->assertJsonCount(2, 'data');

        // 4. Public endpoint /events/categories
        $resPub = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/events/categories');
        $resPub->assertOk();
        $resPub->assertJsonCount(2, 'data');
    }

    public function test_can_filter_events_by_free(): void
    {
        Event::create([
            'title' => ['en' => 'Free Community Meetup', 'ar' => 'لقاء مجتمعي مجاني'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'is_free' => true,
            'price' => 0,
            'currency' => 'USD',
            'status' => 'published',
        ]);

        Event::create([
            'title' => ['en' => 'Paid Boot Camp', 'ar' => 'معسكر تدريبي مدفوع'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(4),
            'is_free' => false,
            'price' => 50,
            'currency' => 'USD',
            'status' => 'published',
        ]);

        // Filter free=1 with lang=en
        $resFree = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/events?free=1');
        $resFree->assertOk();
        $this->assertCount(1, $resFree->json('data'));
        $this->assertEquals('Free Community Meetup', $resFree->json('data.0.title'));

        // Filter free=0 with lang=en
        $resPaid = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/events?free=0');
        $resPaid->assertOk();
        $this->assertCount(1, $resPaid->json('data'));
        $this->assertEquals('Paid Boot Camp', $resPaid->json('data.0.title'));
    }

    public function test_can_filter_events_by_cancelled(): void
    {
        $active = Event::create([
            'title' => ['en' => 'Active Event', 'ar' => 'حدث نشط'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'status' => 'published',
        ]);

        $cancelled = Event::create([
            'title' => ['en' => 'Cancelled Event', 'ar' => 'حدث ملغى'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'status' => 'cancelled',
        ]);

        // Default query: does not return cancelled
        $resDefault = $this->getJson('/api/v1/events');
        $resDefault->assertOk();
        $idsDefault = collect($resDefault->json('data'))->pluck('id')->all();
        $this->assertContains($active->id, $idsDefault);
        $this->assertNotContains($cancelled->id, $idsDefault);

        // Filter cancelled=1: returns only cancelled
        $resCancelled = $this->getJson('/api/v1/events?cancelled=1');
        $resCancelled->assertOk();
        $idsCancelled = collect($resCancelled->json('data'))->pluck('id')->all();
        $this->assertContains($cancelled->id, $idsCancelled);
        $this->assertNotContains($active->id, $idsCancelled);
    }

    public function test_can_search_events_by_name_in_text(): void
    {
        Event::create([
            'title' => ['en' => 'Artificial Intelligence Summit', 'ar' => 'قمة الذكاء الاصطناعي'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'status' => 'published',
        ]);

        Event::create([
            'title' => ['en' => 'Fintech Aleppo Conference', 'ar' => 'مؤتمر التكنولوجيا المالية في حلب'],
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'status' => 'published',
        ]);

        // Search Arabic text with lang=ar
        $resAr = $this->withHeaders(['lang' => 'ar'])->getJson('/api/v1/events?search=الذكاء');
        $resAr->assertOk();
        $this->assertCount(1, $resAr->json('data'));
        $this->assertEquals('قمة الذكاء الاصطناعي', $resAr->json('data.0.title'));

        // Search English text with lang=en
        $resEn = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/events?search=Fintech');
        $resEn->assertOk();
        $this->assertCount(1, $resEn->json('data'));
        $this->assertEquals('Fintech Aleppo Conference', $resEn->json('data.0.title'));
    }

    public function test_can_filter_events_by_category(): void
    {
        $techCat = EventCategory::create([
            'name' => ['en' => 'Tech', 'ar' => 'تكنولوجيا'],
            'slug' => 'tech',
        ]);

        $designCat = EventCategory::create([
            'name' => ['en' => 'Design', 'ar' => 'تصميم'],
            'slug' => 'design',
        ]);

        $techEvent = Event::create([
            'title' => ['en' => 'Vue 3 Deep Dive', 'ar' => 'دورة فيو 3 المتقدمة'],
            'category_id' => $techCat->id,
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(2),
            'status' => 'published',
        ]);

        $designEvent = Event::create([
            'title' => ['en' => 'UI UX Fundamentals', 'ar' => 'أساسيات واجهة المستخدم'],
            'category_id' => $designCat->id,
            'start_at' => now()->addDays(3),
            'end_at' => now()->addDays(3)->addHours(2),
            'status' => 'published',
        ]);

        // Filter by category_id
        $resId = $this->getJson("/api/v1/events?category_id={$techCat->id}");
        $resId->assertOk();
        $this->assertCount(1, $resId->json('data'));
        $this->assertEquals($techEvent->id, $resId->json('data.0.id'));

        // Filter by category_slug
        $resSlug = $this->getJson('/api/v1/events?category_slug=design');
        $resSlug->assertOk();
        $this->assertCount(1, $resSlug->json('data'));
        $this->assertEquals($designEvent->id, $resSlug->json('data.0.id'));

        // Filter by generic category query param
        $resGeneric = $this->getJson('/api/v1/events?category=tech');
        $resGeneric->assertOk();
        $this->assertCount(1, $resGeneric->json('data'));
        $this->assertEquals($techEvent->id, $resGeneric->json('data.0.id'));
    }

    public function test_can_view_event_details_and_personalized_member_registration(): void
    {
        $member = User::factory()->create(['email' => 'registered.member@example.com']);
        $member->assignRole('member');

        $event = Event::create([
            'title' => ['en' => 'Startup Essentials Workshop', 'ar' => 'ورشة أساسيات الشركات الناشئة'],
            'description' => [
                'en' => 'Join us for an interactive session covering the key steps to launch and grow your startup.',
                'ar' => 'انضم إلينا في جلسة تفاعلية تغطي الخطوات الرئيسية لإطلاق وتنمية شركتك الناشئة.',
            ],
            'start_at' => now()->addDays(5)->setTime(10, 0),
            'end_at' => now()->addDays(5)->setTime(12, 0),
            'location' => 'Main Branch – Event Hall',
            'capacity' => 50,
            'is_free' => true,
            'status' => 'published',
        ]);

        // Guest view with lang=en -> is_registered is false, title & description are English strings
        $guestRes = $this->withHeaders(['lang' => 'en'])->getJson("/api/v1/events/{$event->id}");
        $guestRes->assertOk();
        $this->assertIsString($guestRes->json('data.title'));
        $this->assertSame('Startup Essentials Workshop', $guestRes->json('data.title'));
        $this->assertIsString($guestRes->json('data.description'));
        $this->assertSame('Join us for an interactive session covering the key steps to launch and grow your startup.', $guestRes->json('data.description'));
        $this->assertSame('Free', $guestRes->json('data.price_label'));
        $this->assertTrue($guestRes->json('data.can_register'));
        $this->assertFalse($guestRes->json('data.is_registered'));

        // Register member
        EventAttendee::create([
            'event_id' => $event->id,
            'name' => 'Member Name',
            'email' => 'registered.member@example.com',
        ]);

        // Member authenticated view with lang=ar -> is_registered is true, title & description are Arabic strings
        Sanctum::actingAs($member, ['*']);
        $memberRes = $this->withHeaders(['lang' => 'ar'])->getJson("/api/v1/member/events/{$event->id}");
        $memberRes->assertOk();
        $this->assertIsString($memberRes->json('data.title'));
        $this->assertSame('ورشة أساسيات الشركات الناشئة', $memberRes->json('data.title'));
        $this->assertIsString($memberRes->json('data.description'));
        $this->assertSame('انضم إلينا في جلسة تفاعلية تغطي الخطوات الرئيسية لإطلاق وتنمية شركتك الناشئة.', $memberRes->json('data.description'));
        $this->assertSame('مجاني', $memberRes->json('data.price_label'));
        $this->assertTrue($memberRes->json('data.is_registered'));
    }
}
