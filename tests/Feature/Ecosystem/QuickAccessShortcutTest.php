<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Ecosystem\Models\QuickAccessShortcut;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QuickAccessShortcutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'key' => 'events',
            'label' => ['en' => 'Events', 'ar' => 'الفعاليات'],
            'icon_url' => 'https://example.com/icons/events.png',
            'route' => '/events',
        ], $overrides);
    }

    public function test_the_migration_starts_with_the_four_home_shortcuts_in_order(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/quick-access-shortcuts')
            ->assertOk()
            ->assertJsonPath('data.*.key', ['packages', 'cafe', 'transactions', 'sessions'])
            ->assertJsonPath('data.1.label', ['en' => 'Cafe', 'ar' => 'الكافيه'])
            ->assertJsonPath('data.1.route', '/cafe-menu');
    }

    public function test_admin_creates_a_shortcut_with_defaults(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/quick-access-shortcuts', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.key', 'events')
            ->assertJsonPath('data.label.ar', 'الفعاليات')
            ->assertJsonPath('data.icon_url', 'https://example.com/icons/events.png')
            ->assertJsonPath('data.order', 0)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_it_needs_an_icon_a_route_from_the_app_and_a_free_key(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/admin/quick-access-shortcuts', $this->payload([
            'key' => 'cafe',
            'icon_url' => null,
            'route' => 'cafe-menu',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['key', 'icon_url', 'route']);
    }

    public function test_admin_updates_one_keeping_its_own_key_and_gets_a_message(): void
    {
        $this->actingAsAdmin();
        $shortcut = QuickAccessShortcut::query()->where('key', 'cafe')->firstOrFail();

        $this->withHeader('lang', 'en')->putJson("/api/v1/admin/quick-access-shortcuts/{$shortcut->id}", $this->payload([
            'key' => 'cafe',
            'label' => ['en' => 'Café', 'ar' => 'المقهى'],
            'route' => '/cafe-menu',
            'order' => 9,
            'is_active' => false,
        ]))->assertOk()->assertExactJson(['message' => 'Shortcut updated.']);

        $shortcut->refresh();
        $this->assertSame('المقهى', $shortcut->label['ar']);
        $this->assertSame('https://example.com/icons/events.png', $shortcut->icon_url);
        $this->assertSame(9, $shortcut->order);
        $this->assertFalse($shortcut->is_active);
    }

    public function test_admin_deletes_one(): void
    {
        $this->actingAsAdmin();
        $shortcut = QuickAccessShortcut::factory()->create();

        $this->deleteJson("/api/v1/admin/quick-access-shortcuts/{$shortcut->id}")->assertNoContent();

        $this->assertModelMissing($shortcut);
    }

    public function test_a_member_cannot_manage_them(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $this->getJson('/api/v1/admin/quick-access-shortcuts')->assertForbidden();
    }
}
