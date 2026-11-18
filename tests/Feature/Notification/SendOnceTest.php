<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Models\User;
use App\Services\Notification\Drivers\MockPushProvider;
use App\Services\Notification\NotificationService;
use App\Services\Notification\PushProvider;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Notification\Concerns\ReadsNotices;
use Tests\TestCase;

/**
 * A reminder runs every few minutes and finds the same subject again and
 * again: its dedupe key makes it reach each person once.
 */
class SendOnceTest extends TestCase
{
    use ReadsNotices;
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->app->bind(PushProvider::class, fn () => new MockPushProvider);

        $this->member = User::factory()->create();
    }

    public function test_the_same_key_reaches_a_person_once(): void
    {
        $service = app(NotificationService::class);

        $this->assertTrue($service->sendToUserOnce($this->member, 'booking.reminder:55', 'booking.reminder', ['space' => 'A', 'time' => '10:00']));
        $this->assertFalse($service->sendToUserOnce($this->member, 'booking.reminder:55', 'booking.reminder', ['space' => 'A', 'time' => '10:00']));

        $this->assertCount(1, $this->notices($this->member, 'booking.reminder'));
    }

    public function test_another_stage_or_another_person_is_sent_again(): void
    {
        $other = User::factory()->create();
        $service = app(NotificationService::class);
        $params = ['plan' => 'Flexi', 'date' => '2026-11-09'];

        $service->sendToUsersOnce(new Collection([$this->member, $other]), 'membership.expiring_soon:12:7d', 'membership.expiring_soon', $params);
        $service->sendToUsersOnce(new Collection([$this->member, $other]), 'membership.expiring_soon:12:7d', 'membership.expiring_soon', $params);
        $service->sendToUserOnce($this->member, 'membership.expiring_soon:12:1d', 'membership.expiring_soon', $params);

        $this->assertCount(2, $this->notices($this->member, 'membership.expiring_soon'));
        $this->assertCount(1, $this->notices($other, 'membership.expiring_soon'));
    }
}
