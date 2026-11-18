<?php

namespace Tests\Feature\Hr;

use App\Domain\Identity\Models\User;
use App\Notifications\GeneralNotification;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * hr:alerts reaches the people who may read HR alerts, through ADD's own
 * notification service.
 */
class HrAlertsNotificationTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    public function test_digest_goes_to_holders_of_hr_alerts_view_only(): void
    {
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        Notification::fake();

        Role::create(['name' => 'hr_manager', 'guard_name' => 'web'])->givePermissionTo('hr.alerts.view');
        $hrManager = User::factory()->create();
        $hrManager->assignRole('hr_manager');
        $bystander = User::factory()->create();
        $bystander->assignRole('operations');

        $this->hireFor(User::factory()->create(), [
            'contract' => ['type' => 'fixed_term', 'ends_on' => now()->addDays(3)->toDateString()],
        ]);

        $this->artisan('hr:alerts')->assertSuccessful();

        Notification::assertSentTo($hrManager, GeneralNotification::class);
        Notification::assertNotSentTo($bystander, GeneralNotification::class);
    }
}
