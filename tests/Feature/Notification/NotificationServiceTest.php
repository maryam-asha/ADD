<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Enums\NotificationChannel;
use App\Domain\Identity\Enums\NotificationStatus;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);
    }

    public function test_send_to_user_creates_database_notification(): void
    {
        $user = User::factory()->create([
            'preferred_language' => 'en',
            'fcm_token' => 'test-token',
            'status' => 'active',
        ]);
        $user->assignRole('member');

        $service = app(NotificationService::class);
        $service->sendToUser($user, 'booking.approved', ['space' => 'Room A']);

        $this->assertDatabaseCount('notifications', 1);

        $notification = $user->notifications()->first();
        $this->assertEquals('Booking Approved', $notification->data['title']);
        $this->assertStringContainsString('Room A', $notification->data['body']);
    }

    public function test_send_to_user_respects_arabic_preference(): void
    {
        $user = User::factory()->create([
            'preferred_language' => 'ar',
            'fcm_token' => 'test-token',
            'status' => 'active',
        ]);
        $user->assignRole('member');

        $service = app(NotificationService::class);
        $service->sendToUser($user, 'booking.approved', ['space' => 'غرفة أ']);

        $notification = $user->notifications()->first();
        $this->assertEquals('تمت الموافقة على الحجز', $notification->data['title']);
    }

    public function test_send_to_user_logs_push_notification(): void
    {
        $user = User::factory()->create([
            'preferred_language' => 'en',
            'fcm_token' => 'test-token',
            'status' => 'active',
        ]);
        $user->assignRole('member');

        $service = app(NotificationService::class);
        $service->sendToUser($user, 'booking.approved', ['space' => 'Room A']);

        $this->assertDatabaseCount('notification_logs', 1);

        $log = NotificationLog::first();
        $this->assertEquals($user->id, $log->user_id);
        $this->assertEquals(NotificationChannel::Push, $log->channel);
        $this->assertEquals(NotificationStatus::Sent, $log->status);
        $this->assertNotNull($log->payload);
        $this->assertEquals(1, $log->payload['device_count']);
    }

    public function test_send_to_user_with_multiple_devices_delivers_to_all(): void
    {
        $user = User::factory()->create([
            'preferred_language' => 'en',
            'fcm_token' => null,
            'status' => 'active',
        ]);
        $user->assignRole('member');

        $user->deviceTokens()->createMany([
            ['token' => 'token-phone', 'platform' => 'ios'],
            ['token' => 'token-tablet', 'platform' => 'android'],
        ]);

        $service = app(NotificationService::class);
        $service->sendToUser($user, 'booking.approved', ['space' => 'Room B']);

        $this->assertDatabaseCount('notification_logs', 1);
        $log = NotificationLog::first();
        $this->assertEquals(2, $log->payload['device_count']);
    }

    public function test_send_to_user_skips_push_without_tokens(): void
    {
        $user = User::factory()->create([
            'preferred_language' => 'en',
            'fcm_token' => null,
            'status' => 'active',
        ]);
        $user->assignRole('member');

        $service = app(NotificationService::class);
        $service->sendToUser($user, 'booking.approved', ['space' => 'Room A']);

        // Database notification is still created
        $this->assertDatabaseCount('notifications', 1);
        // But no push log since there are no device tokens
        $this->assertDatabaseCount('notification_logs', 0);
    }

    public function test_send_to_users_groups_by_locale(): void
    {
        $enUser = User::factory()->create([
            'preferred_language' => 'en',
            'fcm_token' => 'token-en',
            'status' => 'active',
        ]);
        $enUser->assignRole('member');

        $arUser = User::factory()->create([
            'preferred_language' => 'ar',
            'fcm_token' => 'token-ar',
            'status' => 'active',
        ]);
        $arUser->assignRole('member');

        $service = app(NotificationService::class);
        $service->sendToUsers(
            User::whereIn('id', [$enUser->id, $arUser->id])->get(),
            'wallet.topped_up',
            ['amount' => '100', 'currency' => 'USD', 'points' => '100']
        );

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseCount('notification_logs', 2);

        $enNotification = $enUser->notifications()->first();
        $this->assertEquals('Wallet Topped Up', $enNotification->data['title']);

        $arNotification = $arUser->notifications()->first();
        $this->assertEquals('تم شحن المحفظة', $arNotification->data['title']);
    }

    public function test_mock_push_provider_returns_success(): void
    {
        $provider = new MockPushProvider;

        $result = $provider->sendToDevice('test-token', 'Test', 'Body');
        $this->assertTrue($result);

        $results = $provider->sendToDevices(['token1', 'token2'], 'Test', 'Body');
        $this->assertTrue($results['token1']);
        $this->assertTrue($results['token2']);
    }
}
