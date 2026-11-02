<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Each device gets a push in the language it was last used in, so a phone
 * showing English never receives Arabic because the account says Arabic.
 */
class PushLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->member = User::factory()->create(['status' => 'active', 'preferred_language' => 'ar', 'fcm_token' => null]);
        $this->member->assignRole('member');
    }

    public function test_device_registers_with_the_request_language(): void
    {
        Sanctum::actingAs($this->member, ['member-app']);

        $this->putJson('/api/v1/member/device-token', ['fcm_token' => 'phone-token'], ['lang' => 'en'])->assertOk();

        $this->assertSame('en', $this->member->deviceTokens()->value('language'));
    }

    public function test_device_registered_without_a_language_follows_the_account(): void
    {
        $this->member->update(['preferred_language' => 'en']);
        Sanctum::actingAs($this->member, ['member-app']);

        $this->putJson('/api/v1/member/device-token', ['fcm_token' => 'phone-token'])->assertOk();

        $this->assertNull($this->member->deviceTokens()->value('language'));
    }

    public function test_language_change_updates_this_device(): void
    {
        $bearer = ['Authorization' => 'Bearer '.$this->member->createToken('app', ['member-app'])->plainTextToken];

        $this->putJson('/api/v1/member/device-token', ['fcm_token' => 'phone-token'], $bearer + ['lang' => 'ar'])->assertOk();
        $this->patchJson('/api/v1/member/preferences/language', ['preferred_language' => 'en'], $bearer)->assertOk();

        $this->assertSame('en', $this->member->deviceTokens()->value('language'));
    }

    public function test_each_device_gets_its_own_language(): void
    {
        $this->member->deviceTokens()->createMany([
            ['token' => 'arabic-phone', 'language' => 'ar'],
            ['token' => 'english-phone', 'language' => 'en'],
        ]);

        app(NotificationService::class)->sendToUser($this->member, 'booking.approved', [
            'space' => ['ar' => 'غرفة أ', 'en' => 'Room A'],
        ]);

        $logs = NotificationLog::all()->keyBy(fn (NotificationLog $log) => $log->payload['language']);

        $this->assertCount(2, $logs);
        $this->assertSame('Booking Approved', $logs['en']->payload['title']);
        $this->assertStringContainsString('Room A', $logs['en']->payload['body']);
        $this->assertSame('تمت الموافقة على حجزك', $logs['ar']->payload['title']);
    }

    public function test_device_without_language_uses_account_language(): void
    {
        $this->member->update(['preferred_language' => 'en']);
        $this->member->deviceTokens()->create(['token' => 'old-phone']);

        app(NotificationService::class)->sendToUser($this->member, 'booking.approved', ['space' => 'Room A']);

        $this->assertSame(1, NotificationLog::count());
        $this->assertSame('Booking Approved', NotificationLog::first()->payload['title']);
        $this->assertSame('en', NotificationLog::first()->payload['language']);
    }
}
