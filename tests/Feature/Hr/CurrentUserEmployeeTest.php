<?php

namespace Tests\Feature\Hr;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * auth/me tells the dashboard whether to show "My HR": the account's
 * employee summary, or null.
 */
class CurrentUserEmployeeTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    public function test_employee_is_null_for_an_account_without_an_employee_record(): void
    {
        $this->seed(RoleSeeder::class);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->getJson('/api/v1/auth/me')->assertOk();

        $this->assertArrayHasKey('employee', $response->json());
        $this->assertNull($response->json('employee'));
    }

    public function test_employee_summary_for_an_employee(): void
    {
        $user = User::factory()->create();
        $employee = $this->hireFor($user, ['name' => ['ar' => 'نور', 'en' => 'Nour']]);
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('employee.employee_number', $employee->employee_number)
            ->assertJsonPath('employee.name.en', 'Nour')
            ->assertJsonPath('employee.status', 'active');
    }
}
