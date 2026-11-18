<?php

namespace Tests\Feature\Experience;

use App\Domain\Experience\Models\EventCategory;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventCategoryTest extends TestCase
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

    public function test_admin_can_list_event_categories(): void
    {
        $this->actingAsAdmin();

        EventCategory::create([
            'name' => ['en' => 'Workshops', 'ar' => 'ورش عمل'],
            'slug' => 'workshops',
            'order' => 1,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/admin/event-categories');

        $response->assertOk();
        $response->assertJsonFragment(['slug' => 'workshops']);
    }

    public function test_admin_can_create_event_category(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/event-categories', [
            'name' => ['en' => 'Networking', 'ar' => 'تواصل وتشبيك'],
            'slug' => 'networking',
            'description' => ['en' => 'Networking events', 'ar' => 'فعاليات التعارف والتشبيك'],
            'color' => '#FF5733',
            'icon' => 'carbon:network-4',
            'order' => 2,
            'is_active' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'networking');
        $response->assertJsonPath('data.color', '#FF5733');
        $response->assertJsonPath('data.name.en', 'Networking');

        $this->assertDatabaseHas('event_categories', [
            'slug' => 'networking',
            'color' => '#FF5733',
        ]);
    }

    public function test_admin_can_create_category_with_auto_generated_slug(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/event-categories', [
            'name' => ['en' => 'Startup Pitch', 'ar' => 'عرض المشاريع'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.slug', 'startup-pitch');
        $this->assertDatabaseHas('event_categories', ['slug' => 'startup-pitch']);
    }

    public function test_admin_can_update_event_category(): void
    {
        $this->actingAsAdmin();

        $category = EventCategory::create([
            'name' => ['en' => 'Old Name', 'ar' => 'اسم قديم'],
            'slug' => 'old-slug',
            'is_active' => true,
        ]);

        $response = $this->putJson("/api/v1/admin/event-categories/{$category->id}", [
            'name' => ['en' => 'New Name', 'ar' => 'اسم جديد'],
            'slug' => 'old-slug',
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertEquals('New Name', $category->fresh()->translate('name', 'en'));
        $this->assertFalse($category->fresh()->is_active);
    }

    public function test_admin_can_delete_event_category(): void
    {
        $this->actingAsAdmin();

        $category = EventCategory::create([
            'name' => ['en' => 'To Delete', 'ar' => 'للحذف'],
            'slug' => 'to-delete',
        ]);

        $response = $this->deleteJson("/api/v1/admin/event-categories/{$category->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('event_categories', ['id' => $category->id]);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $this->actingAsAdmin();

        EventCategory::create([
            'name' => ['en' => 'First', 'ar' => 'أول'],
            'slug' => 'shared-slug',
        ]);

        $response = $this->postJson('/api/v1/admin/event-categories', [
            'name' => ['en' => 'Second', 'ar' => 'ثاني'],
            'slug' => 'shared-slug',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['slug']);
    }

    public function test_public_can_only_view_active_event_categories(): void
    {
        EventCategory::create([
            'name' => ['en' => 'Active Cat', 'ar' => 'نشط'],
            'slug' => 'active-cat',
            'is_active' => true,
        ]);

        EventCategory::create([
            'name' => ['en' => 'Inactive Cat', 'ar' => 'معطل'],
            'slug' => 'inactive-cat',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/v1/event-categories');

        $response->assertOk();
        $response->assertJsonFragment(['slug' => 'active-cat']);
        $response->assertJsonMissing(['slug' => 'inactive-cat']);
    }

    public function test_guest_cannot_manage_event_categories(): void
    {
        $response = $this->postJson('/api/v1/admin/event-categories', [
            'name' => ['en' => 'Hackathon', 'ar' => 'هاكاثون'],
            'slug' => 'hackathon',
        ]);

        $response->assertUnauthorized();
    }
}
