<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushProvider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * An inbox shows each notice in the language the reader's device asks for,
 * not the one the account had when it was sent.
 */
class NotificationRenderingTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->member = User::factory()->create(['status' => 'active', 'preferred_language' => 'ar']);
        $this->member->assignRole('member');
    }

    public function test_inbox_renders_in_the_request_language(): void
    {
        app(NotificationService::class)->sendToUser($this->member, 'booking.approved', ['space' => 'Room A']);

        $this->assertSame('Booking Approved', $this->inbox('en')['title']);
        $this->assertSame('تمت الموافقة على الحجز', $this->inbox('ar')['title']);
        $this->assertSame('booking.approved', $this->inbox('en')['template_key']);
    }

    public function test_translatable_param_follows_the_request_language(): void
    {
        app(NotificationService::class)->sendToUser($this->member, 'booking.approved', [
            'space' => ['ar' => 'غرفة أ', 'en' => 'Room A'],
        ]);

        $english = $this->inbox('en')['body'];
        $arabic = $this->inbox('ar')['body'];

        $this->assertStringContainsString('Room A', $english);
        $this->assertStringContainsString('غرفة أ', $arabic);
        $this->assertStringNotContainsString('Array', $english.$arabic);
    }

    public function test_legacy_row_shows_its_stored_text(): void
    {
        $this->member->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\GeneralNotification',
            'data' => ['template_key' => 'booking.approved', 'title' => 'Old', 'body' => 'Old body', 'data' => []],
        ]);

        $this->assertSame('Old', $this->inbox('en')['title']);
        $this->assertSame('Old body', $this->inbox('en')['body']);
    }

    public function test_broadcast_is_read_in_the_request_language(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['dashboard']);

        $this->postJson('/api/v1/admin/notifications/broadcast', [
            'title' => ['en' => 'Hello', 'ar' => 'مرحباً'],
            'body' => ['en' => 'Welcome to ADD', 'ar' => 'أهلاً بك في ADD'],
            'target' => 'users',
            'user_ids' => [$this->member->id],
        ])->assertOk();

        $this->assertSame('Hello', $this->inbox('en')['title']);
        $this->assertSame('مرحباً', $this->inbox('ar')['title']);
    }

    public function test_unknown_template_throws_outside_production(): void
    {
        $this->expectException(LogicException::class);

        app(NotificationService::class)->sendToUser($this->member, 'nope.missing');
    }

    /** @return array<string, mixed> the newest row of the member inbox, read in $lang */
    private function inbox(string $lang): array
    {
        Sanctum::actingAs($this->member, ['member-app']);

        return $this->getJson('/api/v1/member/notifications', ['lang' => $lang])
            ->assertOk()
            ->json('data.0');
    }
}
