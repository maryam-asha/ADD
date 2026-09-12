<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminNotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_broadcast_to_all(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $member = User::factory()->create([
            'status' => 'active',
            'fcm_token' => 'token-1',
            'preferred_language' => 'en',
        ]);
        $member->assignRole('member');

        $response = $this->postJson('/api/v1/admin/notifications/broadcast', [
            'title' => ['en' => 'Hello', 'ar' => 'مرحباً'],
            'body' => ['en' => 'Welcome to ADD', 'ar' => 'أهلاً بك في ADD'],
            'target' => 'all',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => 'Notification broadcast sent.']);
    }

    public function test_admin_can_broadcast_to_specific_role(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $member = User::factory()->create([
            'status' => 'active',
            'preferred_language' => 'ar',
        ]);
        $member->assignRole('member');

        $response = $this->postJson('/api/v1/admin/notifications/broadcast', [
            'title' => ['en' => 'Hello', 'ar' => 'مرحباً'],
            'body' => ['en' => 'Message for members', 'ar' => 'رسالة للأعضاء'],
            'target' => 'role',
            'role_name' => 'member',
        ]);

        $response->assertOk();
    }

    public function test_admin_can_broadcast_to_specific_users(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $member = User::factory()->create([
            'status' => 'active',
            'preferred_language' => 'en',
        ]);
        $member->assignRole('member');

        $response = $this->postJson('/api/v1/admin/notifications/broadcast', [
            'title' => ['en' => 'Hello', 'ar' => 'مرحباً'],
            'body' => ['en' => 'Direct message', 'ar' => 'رسالة مباشرة'],
            'target' => 'users',
            'user_ids' => [$member->id],
        ]);

        $response->assertOk();
    }

    public function test_broadcast_requires_both_languages(): void
    {
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/notifications/broadcast', [
            'title' => ['en' => 'Hello'],
            'body' => ['en' => 'Body'],
            'target' => 'all',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['title.ar', 'body.ar']);
    }

    public function test_operations_cannot_broadcast(): void
    {
        $operator = User::factory()->create(['status' => 'active']);
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $response = $this->postJson('/api/v1/admin/notifications/broadcast', [
            'title' => ['en' => 'Hello', 'ar' => 'مرحباً'],
            'body' => ['en' => 'Test', 'ar' => 'اختبار'],
            'target' => 'all',
        ]);

        $response->assertForbidden();
    }
}
