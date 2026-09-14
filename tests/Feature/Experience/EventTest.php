<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventCategory;
use App\Domain\Finance\Enums\ExchangeRateSource;
use App\Domain\Finance\Models\ExchangeRate;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_can_create_event(): void
    {
        $this->actingAsAdmin();

        $category = EventCategory::create([
            'name' => ['en' => 'Workshops', 'ar' => 'ورش عمل'],
            'slug' => 'workshops',
        ]);

        $response = $this->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Tech Talk', 'ar' => 'حديث تقني'],
            'description' => ['en' => 'All about AI', 'ar' => 'كل شيء عن الذكاء الاصطناعي'],
            'category_id' => $category->id,
            'start_at' => now()->addDays(2)->toDateTimeString(),
            'end_at' => now()->addDays(2)->addHours(2)->toDateTimeString(),
            'location' => 'Main Hall',
            'capacity' => 50,
            'status' => 'published',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title.en', 'Tech Talk');
        $response->assertJsonPath('data.capacity', 50);
        $response->assertJsonPath('data.category_id', $category->id);
        $response->assertJsonPath('data.category.slug', 'workshops');

        $this->assertDatabaseHas('events', [
            'category_id' => $category->id,
            'location' => 'Main Hall',
            'status' => 'published',
            'capacity' => 50,
        ]);
    }

    public function test_admin_cannot_create_event_with_non_existent_category_id(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Invalid Event', 'ar' => 'فعالية غير صالحة'],
            'category_id' => 99999,
            'start_at' => now()->addDays(1)->toDateTimeString(),
            'end_at' => now()->addDays(1)->addHours(2)->toDateTimeString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['category_id']);
    }

    public function test_deleting_event_category_nullifies_event_category_id(): void
    {
        $this->actingAsAdmin();

        $category = EventCategory::create([
            'name' => ['en' => 'Temp Cat', 'ar' => 'فئة مؤقتة'],
            'slug' => 'temp-cat',
        ]);

        $event = Event::create([
            'title' => ['en' => 'Cat Event', 'ar' => 'فعالية فئة'],
            'category_id' => $category->id,
            'start_at' => now()->addDays(1),
            'end_at' => now()->addDays(1)->addHours(2),
        ]);

        $this->assertEquals($category->id, $event->fresh()->category_id);

        $category->delete();

        $this->assertNull($event->fresh()->category_id);
    }

    public function test_admin_can_update_event(): void
    {
        $this->actingAsAdmin();

        $event = Event::create([
            'title' => ['en' => 'Old Title', 'ar' => 'عنوان قديم'],
            'start_at' => now()->addDays(1),
            'end_at' => now()->addDays(1)->addHours(1),
            'status' => 'draft',
        ]);

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/events/{$event->id}", [
            'title' => ['en' => 'Updated Title', 'ar' => 'عنوان محدث'],
            'start_at' => now()->addDays(1)->toDateTimeString(),
            'end_at' => now()->addDays(1)->addHours(2)->toDateTimeString(),
            'status' => 'published',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Event updated.']);

        $this->assertEquals('Updated Title', $event->fresh()->translate('title', 'en'));
        $this->assertEquals('published', $event->fresh()->status);
    }

    public function test_admin_can_delete_event(): void
    {
        $this->actingAsAdmin();

        $event = Event::create([
            'title' => ['en' => 'To Delete', 'ar' => 'للحذف'],
            'start_at' => now()->addDays(1),
            'end_at' => now()->addDays(1)->addHours(1),
        ]);

        $response = $this->deleteJson("/api/v1/admin/events/{$event->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('events', ['id' => $event->id]);
    }

    public function test_public_can_only_view_published_events(): void
    {
        Event::create([
            'title' => ['en' => 'Draft Event', 'ar' => 'مسودة'],
            'start_at' => now()->addDays(1),
            'end_at' => now()->addDays(1)->addHours(1),
            'status' => 'draft',
        ]);

        Event::create([
            'title' => ['en' => 'Published Event', 'ar' => 'منشور'],
            'start_at' => now()->addDays(2),
            'end_at' => now()->addDays(2)->addHours(1),
            'status' => 'published',
        ]);

        $response = $this->withHeaders(['lang' => 'en'])->getJson('/api/v1/events');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Published Event');
    }

    public function test_admin_can_create_paid_event(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Paid Masterclass', 'ar' => 'دورة متقدمة مدفوعة'],
            'start_at' => now()->addDays(3)->toDateTimeString(),
            'end_at' => now()->addDays(3)->addHours(2)->toDateTimeString(),
            'is_free' => false,
            'price_points' => 150,
            'status' => 'published',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_free', false);
        $response->assertJsonPath('data.price_points', 150);
        $response->assertJsonPath('data.price_usd', '15.00');

        $this->assertDatabaseHas('events', [
            'is_free' => false,
            'price_points' => 150,
        ]);
    }

    public function test_admin_can_create_free_event(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Community Gathering', 'ar' => 'لقاء مجتمعي'],
            'start_at' => now()->addDays(4)->toDateTimeString(),
            'end_at' => now()->addDays(4)->addHours(2)->toDateTimeString(),
            'is_free' => true,
            'price_points' => 0,
            'status' => 'published',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_free', true);
        $response->assertJsonPath('data.price_points', 0);
        $response->assertJsonPath('data.price_usd', '0.00');

        $this->assertDatabaseHas('events', [
            'is_free' => true,
            'price_points' => 0,
        ]);
    }

    public function test_event_prices_are_returned_in_both_usd_and_preferred_display_currency(): void
    {
        // Set up exchange rate: 1 USD = 10,000 SYP (rate_to_base = 0.0001)
        ExchangeRate::create([
            'currency_code' => 'SYP',
            'rate_to_base' => '0.0001000000',
            'source' => ExchangeRateSource::Manual,
            'effective_from' => now(),
        ]);

        $event = Event::create([
            'title' => ['en' => 'AI Conference', 'ar' => 'مؤتمر الذكاء الاصطناعي'],
            'start_at' => now()->addDays(5),
            'end_at' => now()->addDays(5)->addHours(3),
            'is_free' => false,
            'price_points' => 100, // 100 pts = 10.00 USD at rate 0.10
            'status' => 'published',
        ]);

        $member = User::factory()->create(['preferred_currency' => 'SYP']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $response = $this->getJson('/api/v1/events');

        $response->assertOk();
        $item = collect($response->json('data'))->firstWhere('id', $event->id);
        $this->assertNotNull($item);
        // The member surface returns display labels only: the points as
        // the member spends them, and the same price in their chosen
        // currency. 100 pts =  .00 = 100,000 SYP at the seeded rate.
        $this->assertSame('100 pts', $item['price_label']);
        $this->assertSame('100,000.00 ل.س', $item['price_display_label']);

        // The raw figures stay server-side — registration debits the
        // wallet and validates the balance there, so a member client has
        // nothing to compute and no way to re-derive the price.
        $this->assertArrayNotHasKey('price_points', $item);
        $this->assertArrayNotHasKey('price_usd', $item);
        $this->assertArrayNotHasKey('price_display', $item);
        $this->assertArrayNotHasKey('display_currency', $item);
    }

    public function test_admin_can_fetch_available_rooms_and_create_event_with_space_id(): void
    {
        $this->actingAsAdmin();

        $branch = Branch::create([
            'name' => ['en' => 'Aleppo Branch', 'ar' => 'فرع حلب'],
            'city' => ['en' => 'Aleppo', 'ar' => 'حلب'],
            'timezone' => 'Asia/Damascus',
            'is_default' => true,
        ]);

        $building = Building::create([
            'branch_id' => $branch->id,
            'name' => ['en' => 'Main Building', 'ar' => 'المبنى الرئيسي'],
            'floor_count' => 3,
        ]);

        $space = Space::factory()
            ->eventHall()
            ->create([
                'building_id' => $building->id,
                'capacity' => 100,
            ]);

        // 1. Fetch available rooms
        $resRooms = $this->withHeaders(['lang' => 'ar'])->getJson('/api/v1/admin/events/rooms');
        $resRooms->assertOk();
        $this->assertCount(1, $resRooms->json('data'));
        $this->assertSame($space->id, $resRooms->json('data.0.id'));
        $this->assertSame('قاعة الفعاليات', $resRooms->json('data.0.name'));
        $this->assertSame('فرع حلب', $resRooms->json('data.0.branch_name'));

        // 2. Create event without typing location directly; location is automatically Branch + Room
        $response = $this->withHeaders(['lang' => 'ar'])->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Tech Gathering', 'ar' => 'ملتقى تقني'],
            'space_id' => $space->id,
            'start_at' => now()->addDays(2)->toDateTimeString(),
            'end_at' => now()->addDays(2)->addHours(2)->toDateTimeString(),
        ]);

        $response->assertCreated();
        $this->assertSame($space->id, $response->json('data.space_id'));
        $this->assertSame('فرع حلب – قاعة الفعاليات', $response->json('data.location'));
        $this->assertSame($branch->id, $response->json('data.branch_id'));
        $this->assertSame(100, $response->json('data.capacity'));

        $this->assertDatabaseHas('events', [
            'id' => $response->json('data.id'),
            'space_id' => $space->id,
            'branch_id' => $branch->id,
            'location' => 'فرع حلب – قاعة الفعاليات',
            'capacity' => 100,
        ]);

        // 3. Create event with room name in English
        $resEn = $this->withHeaders(['lang' => 'en'])->postJson('/api/v1/admin/events', [
            'title' => ['en' => 'Workshop', 'ar' => 'ورشة'],
            'branch_id' => $branch->id,
            'room' => 'meeting_room',
            'start_at' => now()->addDays(3)->toDateTimeString(),
            'end_at' => now()->addDays(3)->addHours(2)->toDateTimeString(),
        ]);

        $resEn->assertCreated();
        $this->assertSame('Aleppo Branch – Meeting Room', $resEn->json('data.location'));
    }
}
