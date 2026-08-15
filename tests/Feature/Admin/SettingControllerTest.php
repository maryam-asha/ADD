<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\Models\User;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Services\SettingService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class SettingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        app(SettingService::class)->set('booking.buffer_minutes', 0, SettingValueType::Int);
        app(SettingService::class)->set('module.cafe.is_enabled', true, SettingValueType::Bool);
    }

    public function test_an_admin_can_list_settings(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->getJson('/api/v1/admin/settings');

        $response->assertOk();
        $response->assertJsonFragment(['key' => 'booking.buffer_minutes', 'value' => 0]);
        $response->assertJsonFragment(['key' => 'module.cafe.is_enabled', 'value' => true]);
    }

    public function test_an_operations_user_can_list_settings(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/admin/settings')->assertOk();
    }

    public function test_an_admin_can_update_an_int_setting(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson('/api/v1/admin/settings/booking.buffer_minutes', ['value' => 15]);

        $response->assertOk();
        $response->assertJson(['message' => __('api.admin.setting_updated')]);
        $this->assertSame(15, app(SettingService::class)->get('booking.buffer_minutes'));
    }

    public function test_an_admin_can_update_a_bool_setting(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson('/api/v1/admin/settings/module.cafe.is_enabled', ['value' => false])->assertOk();

        $this->assertFalse(app(SettingService::class)->get('module.cafe.is_enabled'));
    }

    public function test_updating_a_setting_rejects_the_wrong_value_type(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->patchJson('/api/v1/admin/settings/booking.buffer_minutes', ['value' => 'not-a-number']);

        $response->assertStatus(422);
    }

    public function test_updating_an_unknown_key_returns_404(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson('/api/v1/admin/settings/no.such.key', ['value' => 1])->assertNotFound();
    }

    public function test_an_operations_user_cannot_update_a_setting(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->patchJson('/api/v1/admin/settings/booking.buffer_minutes', ['value' => 15])->assertForbidden();
    }

    public function test_updating_a_setting_writes_an_audit_log_entry(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->patchJson('/api/v1/admin/settings/booking.buffer_minutes', ['value' => 15])->assertOk();

        $activity = Activity::where('description', 'setting_updated')->latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame(0, $activity->properties['before']);
        $this->assertSame(15, $activity->properties['after']);
    }
}
