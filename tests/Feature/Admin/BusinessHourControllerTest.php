<?php

namespace Tests\Feature\Admin;

use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessHourControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_an_admin_can_create_a_business_hour(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hours', [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('business_hours', [
            'branch_id' => $branch->id,
            'day_of_week' => 'monday',
            'open_time' => '08:00',
            'close_time' => '17:00',
        ]);
    }

    public function test_close_time_must_be_strictly_after_open_time(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hours', [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '17:00',
            'close_time' => '17:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_overlapping_periods_on_the_same_weekday_are_rejected(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hours', [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '11:00',
            'close_time' => '15:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_non_overlapping_periods_on_the_same_weekday_are_accepted(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hours', [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '15:00',
            'close_time' => '20:00',
        ]);

        $response->assertCreated();
    }

    public function test_updating_a_business_hour_excludes_itself_from_the_overlap_check(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        $businessHour = BusinessHour::factory()->create([
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);

        $response = $this->putJson("/api/v1/admin/business-hours/{$businessHour->id}", [
            'branch_id' => $branch->id,
            'day_of_week' => DayOfWeek::Monday->value,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => __('api.admin.business_hour_updated')]);
        $this->assertDatabaseHas('business_hours', ['id' => $businessHour->id, 'open_time' => '09:00', 'close_time' => '13:00']);
    }

    public function test_index_can_be_filtered_by_branch_id(): void
    {
        $this->actingAsAdmin();
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        BusinessHour::factory()->create(['branch_id' => $branchA->id]);
        BusinessHour::factory()->create(['branch_id' => $branchB->id]);

        $response = $this->getJson("/api/v1/admin/business-hours?branch_id={$branchA->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_an_operations_user_can_list_but_not_delete(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $businessHour = BusinessHour::factory()->create();

        $this->getJson('/api/v1/admin/business-hours')->assertOk();
        $this->deleteJson("/api/v1/admin/business-hours/{$businessHour->id}")->assertForbidden();
    }

    public function test_an_admin_can_delete_a_business_hour(): void
    {
        $this->actingAsAdmin();
        $businessHour = BusinessHour::factory()->create();

        $this->deleteJson("/api/v1/admin/business-hours/{$businessHour->id}")->assertNoContent();
        $this->assertDatabaseMissing('business_hours', ['id' => $businessHour->id]);
    }
}
