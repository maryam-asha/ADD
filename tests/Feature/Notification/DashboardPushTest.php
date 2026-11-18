<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Enums\DeviceSurface;
use App\Domain\Identity\Enums\NotificationAudience;
use App\Domain\Identity\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;
use App\Services\Notification\PushProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff and provider notices reach the dashboard browsers of the account
 * as a data-only push the dashboard's service worker shows itself; member
 * notices keep going to the app. A person who is both never gets one on
 * the other's screen.
 */
class DashboardPushTest extends TestCase
{
    use RefreshDatabase;

    private object $push;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->push = new class implements PushProvider
        {
            /** @var list<array{tokens: list<string>, title: string, body: string, data: array}> */
            public array $notifications = [];

            /** @var list<array{tokens: list<string>, data: array}> */
            public array $data = [];

            public function sendToDevice(string $fcmToken, string $title, string $body, array $data = []): bool
            {
                $this->notifications[] = ['tokens' => [$fcmToken], 'title' => $title, 'body' => $body, 'data' => $data];

                return true;
            }

            public function sendToDevices(array $fcmTokens, string $title, string $body, array $data = []): array
            {
                $this->notifications[] = ['tokens' => $fcmTokens, 'title' => $title, 'body' => $body, 'data' => $data];

                return array_fill_keys($fcmTokens, true);
            }

            public function sendDataToDevices(array $tokens, array $data): array
            {
                $this->data[] = ['tokens' => $tokens, 'data' => $data];

                return array_fill_keys($tokens, true);
            }
        };

        $this->app->instance(PushProvider::class, $this->push);
    }

    public function test_staff_notice_pushes_data_only_to_dashboard_tokens(): void
    {
        $person = $this->personWithBothDevices();
        $params = ['sender_name' => 'Sara', 'subject' => 'Offices'];

        app(NotificationService::class)->sendToUser($person, 'inquiry.new_received', $params, ['target_type' => 'inquiry'], NotificationAudience::Staff);

        $this->assertSame([], $this->push->notifications);
        $this->assertCount(1, $this->push->data);
        ['tokens' => $tokens, 'data' => $data] = $this->push->data[0];
        $text = NotificationText::render('inquiry.new_received', $params, 'ar');

        $this->assertSame(['web-1'], $tokens);
        $this->assertSame($text['title'], $data['title']);
        $this->assertSame($text['body'], $data['body']);
        $this->assertSame($person->notifications()->sole()->id, $data['notification_id']);
        $this->assertSame('staff', $data['audience']);
        $this->assertSame('inquiry.new_received', $data['template_key']);
        $this->assertSame('inquiry', $data['target_type']);
        $this->assertContainsOnly('string', $data);
    }

    public function test_provider_notice_pushes_to_dashboard_tokens(): void
    {
        $person = $this->personWithBothDevices();

        app(NotificationService::class)->sendToUser($person, 'provider.employee_joined', ['name' => 'Omar', 'company' => 'Acme'], audience: NotificationAudience::Provider);

        $this->assertSame([], $this->push->notifications);
        $this->assertSame(['web-1'], $this->push->data[0]['tokens']);
        $this->assertSame('provider', $this->push->data[0]['data']['audience']);
    }

    public function test_member_notice_never_reaches_a_dashboard_browser(): void
    {
        $person = $this->personWithBothDevices();

        app(NotificationService::class)->sendToUser($person, 'inquiry.new_received', ['sender_name' => 'Sara', 'subject' => 'Offices']);

        $this->assertSame([], $this->push->data);
        $this->assertCount(1, $this->push->notifications);
        $this->assertSame(['app-1'], $this->push->notifications[0]['tokens']);
    }

    public function test_legacy_fallback_ignores_dashboard_tokens(): void
    {
        $person = User::factory()->create(['status' => 'active', 'preferred_language' => 'en', 'fcm_token' => 'legacy-1']);
        $person->deviceTokens()->create(['token' => 'web-1', 'surface' => DeviceSurface::Dashboard]);
        $service = app(NotificationService::class);

        $service->sendToUser($person, 'inquiry.new_received', ['sender_name' => 'Sara', 'subject' => 'Offices']);
        $service->sendToUser($person, 'inquiry.new_received', ['sender_name' => 'Sara', 'subject' => 'Offices'], audience: NotificationAudience::Staff);

        $this->assertSame(['legacy-1'], $this->push->notifications[0]['tokens']);
        $this->assertSame(['web-1'], $this->push->data[0]['tokens']);
    }

    public function test_a_staff_notice_with_no_browser_pushes_nothing(): void
    {
        $person = User::factory()->create(['status' => 'active', 'fcm_token' => 'legacy-1']);

        app(NotificationService::class)->sendToUser($person, 'inquiry.new_received', ['sender_name' => 'Sara', 'subject' => 'Offices'], audience: NotificationAudience::Staff);

        $this->assertSame([], $this->push->data);
        $this->assertSame([], $this->push->notifications);
    }

    public function test_broadcast_skips_accounts_with_only_dashboard_tokens(): void
    {
        $staff = User::factory()->create(['status' => 'active', 'fcm_token' => null]);
        $staff->deviceTokens()->create(['token' => 'web-1', 'surface' => DeviceSurface::Dashboard]);
        $member = User::factory()->create(['status' => 'active', 'fcm_token' => null]);
        $member->deviceTokens()->create(['token' => 'app-1', 'surface' => DeviceSurface::App]);

        app(NotificationService::class)->broadcast('inquiry.new_received', ['sender_name' => 'Sara', 'subject' => 'Offices']);

        $this->assertSame(0, $staff->notifications()->count());
        $this->assertSame(1, $member->notifications()->count());
    }

    private function personWithBothDevices(): User
    {
        $person = User::factory()->create(['status' => 'active', 'preferred_language' => 'en', 'fcm_token' => 'app-1']);
        $person->deviceTokens()->createMany([
            ['token' => 'app-1', 'surface' => DeviceSurface::App, 'language' => 'en'],
            ['token' => 'web-1', 'surface' => DeviceSurface::Dashboard, 'language' => 'ar'],
        ]);

        return $person;
    }
}
