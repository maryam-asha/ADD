<?php

namespace Tests\Feature\Notification;

use App\Domain\Identity\Enums\DeviceSurface;
use App\Domain\Identity\Models\DeviceToken;
use App\Domain\Identity\Models\User;
use App\Domain\Providers\Models\Provider;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A dashboard browser registers its own push token, kept apart from the
 * member app's: staff and provider notices go to the browser, member
 * notices to the phone, and neither ever reaches the other.
 */
class DashboardDeviceTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    public function test_staff_registers_a_dashboard_token(): void
    {
        $staff = $this->staff();
        Sanctum::actingAs($staff, ['dashboard']);

        $this->putJson('/api/v1/admin/device-token', ['token' => 'web-1'], ['lang' => 'ar'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        $device = DeviceToken::query()->sole();
        $this->assertSame(DeviceSurface::Dashboard, $device->surface);
        $this->assertSame('ar', $device->language);
        $this->assertNull($device->access_token_id);
        $this->assertNull($staff->fresh()->fcm_token);
    }

    public function test_an_account_without_permissions_is_refused(): void
    {
        $member = User::factory()->create(['status' => 'active']);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['dashboard']);

        $this->putJson('/api/v1/admin/device-token', ['token' => 'web-1'])->assertForbidden();
        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_provider_registers_on_its_own_route(): void
    {
        $provider = Provider::factory()->create();
        $account = User::factory()->create(['status' => 'active']);
        $account->assignRole('provider');
        $provider->users()->attach($account->id);
        Sanctum::actingAs($account, ['dashboard']);

        $this->putJson('/api/v1/provider/device-token', ['token' => 'web-1'])->assertOk();

        $this->assertSame(DeviceSurface::Dashboard, DeviceToken::query()->sole()->surface);
    }

    public function test_the_token_is_required(): void
    {
        Sanctum::actingAs($this->staff(), ['dashboard']);

        $this->putJson('/api/v1/admin/device-token', [])->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_delete_forgets_only_that_browser(): void
    {
        $staff = $this->staff();
        Sanctum::actingAs($staff, ['dashboard']);
        $this->putJson('/api/v1/admin/device-token', ['token' => 'web-1'])->assertOk();
        $this->putJson('/api/v1/admin/device-token', ['token' => 'web-2'])->assertOk();

        $this->deleteJson('/api/v1/admin/device-token', ['token' => 'web-1'])->assertOk();

        $this->assertSame(['web-2'], $staff->deviceTokens()->pluck('token')->all());
    }

    public function test_member_app_tokens_stay_app(): void
    {
        $member = User::factory()->create(['status' => 'active', 'fcm_token' => null]);
        $member->assignRole('member');
        Sanctum::actingAs($member, ['member-app']);

        $this->putJson('/api/v1/member/device-token', ['fcm_token' => 'phone-1'])->assertOk();

        $this->assertSame(DeviceSurface::App, DeviceToken::query()->sole()->surface);
        $this->assertSame('phone-1', $member->fresh()->fcm_token);
    }

    public function test_member_forget_all_leaves_dashboard_tokens(): void
    {
        $person = User::factory()->create(['status' => 'active']);
        $person->assignRole(['member', 'admin']);
        $person->deviceTokens()->createMany([
            ['token' => 'phone-1', 'surface' => DeviceSurface::App],
            ['token' => 'web-1', 'surface' => DeviceSurface::Dashboard],
        ]);
        Sanctum::actingAs($person, ['member-app']);

        $this->deleteJson('/api/v1/member/device-token')->assertOk();

        $this->assertSame(['web-1'], $person->deviceTokens()->pluck('token')->all());
    }

    public function test_a_dashboard_token_never_becomes_the_legacy_phone_token(): void
    {
        $person = User::factory()->create(['status' => 'active', 'fcm_token' => 'phone-1']);
        $person->assignRole(['member', 'admin']);
        $person->deviceTokens()->create(['token' => 'phone-1', 'surface' => DeviceSurface::App]);
        Sanctum::actingAs($person, ['dashboard']);

        $this->putJson('/api/v1/admin/device-token', ['token' => 'web-1'])->assertOk();
        $this->deleteJson('/api/v1/admin/device-token', ['token' => 'web-1'])->assertOk();

        $this->assertSame('phone-1', $person->fresh()->fcm_token);
    }

    public function test_the_same_browser_moves_to_the_next_account(): void
    {
        $first = $this->staff();
        $second = $this->staff();

        Sanctum::actingAs($first, ['dashboard']);
        $this->putJson('/api/v1/admin/device-token', ['token' => 'web-1'])->assertOk();
        Sanctum::actingAs($second, ['dashboard']);
        $this->putJson('/api/v1/admin/device-token', ['token' => 'web-1'])->assertOk();

        $this->assertSame($second->id, DeviceToken::query()->sole()->user_id);
    }

    private function staff(): User
    {
        $staff = User::factory()->create(['status' => 'active', 'fcm_token' => null]);
        $staff->givePermissionTo('bookings.view');

        return $staff;
    }
}
