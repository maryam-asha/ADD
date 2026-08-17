<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactLinkAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_a_contact_link_with_real_defaults(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/contact-links', [
            'type' => 'social_instagram',
            'value' => 'https://instagram.com/adddistrict',
            'label' => 'Instagram',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.sort_order', 0);
        $response->assertJsonPath('data.is_visible', true);
        $this->assertDatabaseHas('contact_links', [
            'type' => 'social_instagram',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
    }

    public function test_a_new_platform_type_is_accepted_without_any_allow_list(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/contact-links', [
            'type' => 'social_threads',
            'value' => '@add_district',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.type', 'social_threads');
    }

    public function test_operations_can_list_admin_can_update_and_delete(): void
    {
        $operations = User::factory()->create();
        $operations->assignRole('operations');
        Sanctum::actingAs($operations, ['*']);

        $link = ContactLink::create([
            'type' => 'website',
            'value' => 'https://example.com',
            'sort_order' => 0,
            'is_visible' => true,
        ]);

        $this->getJson('/api/v1/admin/contact-links')
            ->assertOk()
            ->assertJsonPath('data.0.id', $link->id);

        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->withHeader('lang', 'en')
            ->putJson("/api/v1/admin/contact-links/{$link->id}", [
                'type' => 'website',
                'value' => 'https://newsite.example.com',
                'is_visible' => false,
            ])
            ->assertOk()
            ->assertExactJson(['message' => 'Contact link updated.']);

        $this->assertDatabaseHas('contact_links', [
            'id' => $link->id,
            'value' => 'https://newsite.example.com',
            'is_visible' => 0,
        ]);

        $this->deleteJson("/api/v1/admin/contact-links/{$link->id}")->assertNoContent();
        $this->assertDatabaseMissing('contact_links', ['id' => $link->id]);
    }

    public function test_admin_index_orders_by_sort_order_not_insertion_order(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $second = ContactLink::create(['type' => 'phone', 'value' => '+963000000', 'sort_order' => 2]);
        $first = ContactLink::create(['type' => 'email', 'value' => 'hi@example.com', 'sort_order' => 1]);

        $response = $this->getJson('/api/v1/admin/contact-links');

        $response->assertOk();
        $response->assertJsonPath('data.0.id', $first->id);
        $response->assertJsonPath('data.1.id', $second->id);
    }

    public function test_a_member_cannot_manage_contact_links(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/contact-links')->assertForbidden();
    }
}
