<?php

namespace Tests\Feature\Identity;

use App\Domain\Foundation\Models\Amenity;
use App\Domain\Identity\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dashboard access by permission alone
 * (docs/superpowers/specs/2026-10-27-permission-only-dashboard-access-design.md):
 * any role, whatever its name, reaches exactly the admin API its permissions
 * grant. The permissions, admin's full set and operations' carried-over
 * grant all come from the 2026_10_27 migration, which RefreshDatabase runs —
 * nothing is seeded here.
 */
class PermissionOnlyAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $permissions
     */
    private function actingAsCustomRole(string $roleName, array $permissions = []): User
    {
        Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);

        return $this->actingAsRole($roleName);
    }

    private function actingAsRole(string $roleName): User
    {
        $user = User::factory()->create();
        $user->assignRole($roleName);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_a_custom_role_with_only_amenities_view_reads_amenities_and_cannot_create_one(): void
    {
        $this->actingAsCustomRole('stack_holder', ['amenities.view']);
        $amenity = Amenity::factory()->create();

        $this->getJson('/api/v1/admin/amenities')->assertOk();
        $this->getJson("/api/v1/admin/amenities/{$amenity->id}")->assertOk();

        $this->postJson('/api/v1/admin/amenities', [
            'name' => ['en' => 'Wi-Fi', 'ar' => 'واي فاي'],
        ])->assertForbidden();
        $this->deleteJson("/api/v1/admin/amenities/{$amenity->id}")->assertForbidden();
    }

    public function test_a_custom_role_without_permissions_is_refused_the_reception_desk(): void
    {
        $this->actingAsCustomRole('stack_holder');

        $this->getJson('/api/v1/admin/reception/summary')->assertForbidden();
    }

    public function test_a_custom_role_reaches_the_reception_desk_through_its_permission(): void
    {
        $this->seed(SettingSeeder::class);
        $this->actingAsCustomRole('front_desk', ['reception.view']);

        $this->getJson('/api/v1/admin/reception/summary')->assertOk();
        // Seeing the desk is not booking at it.
        $this->postJson('/api/v1/admin/reception/bookings/quote', [])->assertForbidden();
    }

    public function test_an_any_of_route_takes_either_permission(): void
    {
        $this->actingAsCustomRole('desk', ['reception.view']);
        $this->getJson('/api/v1/admin/users')->assertOk();

        $this->actingAsCustomRole('profiles', ['members.view']);
        $this->getJson('/api/v1/admin/users')->assertOk();

        $this->actingAsCustomRole('stack_holder', ['amenities.view']);
        $this->getJson('/api/v1/admin/users')->assertForbidden();
    }

    public function test_me_and_media_upload_take_any_staff_account_and_refuse_one_without_permissions(): void
    {
        $this->actingAsCustomRole('stack_holder', ['amenities.view']);
        $this->getJson('/api/v1/admin/me')->assertOk();
        // Reaches the controller: an empty upload fails validation, not authorization.
        $this->postJson('/api/v1/admin/media/upload', [])->assertUnprocessable();

        $this->actingAsCustomRole('nobody');
        $this->getJson('/api/v1/admin/me')->assertForbidden();
        $this->postJson('/api/v1/admin/media/upload', [])->assertForbidden();
    }

    public function test_member_and_provider_accounts_are_refused_the_admin_api(): void
    {
        foreach (['member', 'provider'] as $role) {
            Role::findOrCreate($role, 'web');
            $this->actingAsRole($role);

            $this->getJson('/api/v1/admin/me')->assertForbidden();
            $this->getJson('/api/v1/admin/amenities')->assertForbidden();
        }
    }

    public function test_admin_holds_every_permission_after_migrating(): void
    {
        $this->assertGreaterThan(0, Permission::count());
        $this->assertSame(Permission::count(), Role::findByName('admin', 'web')->permissions()->count());
    }

    public function test_admin_reaches_what_used_to_be_admin_only(): void
    {
        $this->actingAsRole('admin');

        $this->getJson('/api/v1/admin/roles')->assertOk();
        $this->getJson('/api/v1/admin/memberships')->assertOk();
        $this->getJson('/api/v1/admin/service-types')->assertOk();
        $this->getJson('/api/v1/admin/kiosks')->assertOk();
    }

    public function test_operations_still_reaches_a_sample_of_what_it_reached_before(): void
    {
        $this->seed(SettingSeeder::class);
        $this->actingAsRole('operations');

        foreach ([
            '/api/v1/admin/me',
            '/api/v1/admin/amenities',
            '/api/v1/admin/buildings',
            '/api/v1/admin/plans',
            '/api/v1/admin/coupons',
            '/api/v1/admin/reports/discounts',
            '/api/v1/admin/events',
            '/api/v1/admin/currencies',
            '/api/v1/admin/exchange-rates',
            '/api/v1/admin/companies',
            '/api/v1/admin/bookings',
            '/api/v1/admin/calendar',
            '/api/v1/admin/reception/summary',
            '/api/v1/admin/reception/arrival-requests',
            '/api/v1/admin/reception/sessions/active',
            '/api/v1/admin/reception/cash-register-sessions',
            '/api/v1/admin/item-sales',
            '/api/v1/admin/site-inquiries',
            '/api/v1/admin/error-logs',
            '/api/v1/admin/users',
            '/api/v1/admin/payments',
            '/api/v1/admin/payment-methods',
            '/api/v1/admin/settings',
            '/api/v1/admin/privacy-policy',
            '/api/v1/admin/branches',
        ] as $uri) {
            $this->assertNotSame(403, $this->getJson($uri)->status(), "operations lost {$uri}");
        }
    }

    public function test_operations_is_still_refused_what_was_admin_only(): void
    {
        $this->actingAsRole('operations');

        $this->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->getJson('/api/v1/admin/memberships')->assertForbidden();
        $this->getJson('/api/v1/admin/service-types')->assertForbidden();
        $this->getJson('/api/v1/admin/service-requests')->assertForbidden();
        $this->getJson('/api/v1/admin/kiosks')->assertForbidden();
        $this->postJson('/api/v1/admin/notifications/broadcast', [])->assertForbidden();
        $this->patchJson('/api/v1/admin/settings/anything', [])->assertForbidden();
        $this->postJson('/api/v1/admin/coupons', [])->assertForbidden();
    }

    public function test_operations_keeps_its_branches_grant_and_never_gets_a_destroy_it_lacked(): void
    {
        $operations = Role::findByName('operations', 'web');

        $this->assertTrue($operations->hasPermissionTo('branches.view'));
        $this->assertFalse($operations->hasPermissionTo('branches.delete'));
        $this->assertFalse($operations->hasPermissionTo('buildings.delete'));
        $this->assertTrue($operations->hasPermissionTo('reception.check_in'));
    }
}
