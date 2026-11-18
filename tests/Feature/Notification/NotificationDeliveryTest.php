<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * A notification is delivered in the request (the host runs no queue
 * worker), and only once the change it announces is saved.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->member = User::factory()->create(['status' => 'active', 'preferred_language' => 'en', 'fcm_token' => 'phone']);
        $this->member->assignRole('member');
    }

    public function test_rolled_back_change_sends_nothing(): void
    {
        // The row would roll back with the transaction anyway; the push
        // would not — it must wait for the commit.
        $pushes = new class extends MockPushProvider
        {
            public int $calls = 0;

            public function sendToDevices(array $fcmTokens, string $title, string $body, array $data = []): array
            {
                $this->calls++;

                return parent::sendToDevices($fcmTokens, $title, $body, $data);
            }
        };
        $this->app->instance(PushProvider::class, $pushes);

        try {
            DB::transaction(function () {
                app(NotificationService::class)->sendToUser($this->member, 'booking.approved', ['space' => 'Room A']);

                throw new RuntimeException('the booking was not saved');
            });
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, $pushes->calls);
    }

    public function test_notification_is_written_without_a_queue(): void
    {
        Queue::fake();

        app(NotificationService::class)->sendToUser($this->member, 'booking.approved', ['space' => 'Room A']);

        $this->assertDatabaseCount('notifications', 1);
        Queue::assertNothingPushed();
    }
}
