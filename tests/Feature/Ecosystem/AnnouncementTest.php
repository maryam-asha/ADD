<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementTest extends TestCase
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

    public function test_admin_can_create_an_announcement_with_real_defaults(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/announcements', [
            'type' => 'offer',
            'image_url' => 'https://example.com/offer.png',
            'link_url' => 'https://example.com/offer',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $response->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('announcements', [
            'type' => 'offer',
            'sort_order' => 0,
            'is_active' => 1,
        ]);
    }

    public function test_a_new_type_string_is_accepted_without_any_migration_or_allow_list(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/announcements', [
            'type' => 'holiday_hours',
            'image_url' => 'https://example.com/holiday.png',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'holiday_hours');
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/announcements', [
            'type' => 'event',
            'image_url' => 'https://example.com/event.png',
            'starts_at' => now()->addDays(5)->toIso8601String(),
            'ends_at' => now()->addDays(1)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('ends_at');
    }

    public function test_operations_can_list_admin_can_update_and_delete(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $announcement = Announcement::factory()->create(['sort_order' => 0]);

        $this->getJson('/api/v1/admin/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.id', $announcement->id);

        $this->actingAsAdmin();

        $this->withHeader('lang', 'en')
            ->putJson("/api/v1/admin/announcements/{$announcement->id}", [
                'type' => $announcement->type,
                'image_url' => 'https://example.com/new.png',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertExactJson(['message' => 'Announcement updated.']);

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement->id,
            'image_url' => 'https://example.com/new.png',
            'is_active' => 0,
        ]);

        $this->deleteJson("/api/v1/admin/announcements/{$announcement->id}")->assertNoContent();
        $this->assertDatabaseMissing('announcements', ['id' => $announcement->id]);
    }

    public function test_admin_index_orders_by_sort_order_not_insertion_order(): void
    {
        $this->actingAsAdmin();

        $second = Announcement::factory()->create(['sort_order' => 2]);
        $first = Announcement::factory()->create(['sort_order' => 1]);

        $response = $this->getJson('/api/v1/admin/announcements');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
    }

    public function test_a_member_cannot_manage_announcements(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/announcements')->assertForbidden();
    }
}
