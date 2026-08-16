<?php

namespace Tests\Feature\Admin;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\BusinessHourException;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessHourExceptionControllerTest extends TestCase
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

    public function test_an_admin_can_create_a_closed_entirely_exception(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
            'is_closed' => true,
            'reason' => 'Holiday',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('business_hour_exceptions', [
            'branch_id' => $branch->id,
            'is_closed' => true,
            'open_time' => null,
            'close_time' => null,
        ]);
    }

    public function test_an_admin_can_create_a_shortened_hours_exception(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
            'reason' => 'Ramadan hours',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('business_hour_exceptions', [
            'branch_id' => $branch->id,
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);
    }

    public function test_open_time_and_close_time_are_prohibited_when_closed_entirely(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
            'is_closed' => true,
            'open_time' => '08:00',
            'close_time' => '12:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_open_time_and_close_time_are_required_when_not_closed_entirely(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
        ]);

        $response->assertStatus(422);
    }

    public function test_close_time_must_be_strictly_after_open_time(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '13:00',
            'close_time' => '13:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_add_a_period_when_the_date_already_has_a_closed_entirely_exception(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->closedEntirely()->create([
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-12-25',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_cannot_mark_closed_entirely_when_the_date_already_has_period_rows(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => true,
        ]);

        $response->assertStatus(422);
    }

    public function test_overlapping_periods_on_the_same_date_are_rejected(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '12:00',
            'close_time' => '15:00',
        ]);

        $response->assertStatus(422);
    }

    public function test_two_period_exception_day_is_accepted(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response = $this->postJson('/api/v1/admin/business-hour-exceptions', [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '16:00',
            'close_time' => '20:00',
        ]);

        $response->assertCreated();
    }

    public function test_updating_an_exception_excludes_itself_from_the_conflict_checks(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();
        $exception = BusinessHourException::factory()->create([
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '13:00',
        ]);

        $response = $this->putJson("/api/v1/admin/business-hour-exceptions/{$exception->id}", [
            'branch_id' => $branch->id,
            'date' => '2026-04-10',
            'is_closed' => false,
            'open_time' => '10:00',
            'close_time' => '14:00',
        ]);

        $response->assertOk();
        $response->assertJson(['message' => __('api.admin.business_hour_exception_updated')]);
    }

    public function test_an_operations_user_can_list_but_not_delete(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);
        $exception = BusinessHourException::factory()->create();

        $this->getJson('/api/v1/admin/business-hour-exceptions')->assertOk();
        $this->deleteJson("/api/v1/admin/business-hour-exceptions/{$exception->id}")->assertForbidden();
    }

    public function test_an_admin_can_delete_an_exception(): void
    {
        $this->actingAsAdmin();
        $exception = BusinessHourException::factory()->create();

        $this->deleteJson("/api/v1/admin/business-hour-exceptions/{$exception->id}")->assertNoContent();
        $this->assertDatabaseMissing('business_hour_exceptions', ['id' => $exception->id]);
    }
}
