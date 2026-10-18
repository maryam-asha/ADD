<?php

namespace Tests\Feature\Hr;

use Add\Hr\Core\Models\DocumentType;
use Add\Hr\Core\Services\DocumentVault;
use App\Domain\Identity\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\Feature\Hr\Concerns\HiresAddEmployees;
use Tests\TestCase;

/**
 * Who reaches which HR surface in ADD (routes/api.php): every employee
 * reaches hr/me whatever their role; hr/* admin needs an hr.* permission,
 * which any role — not only admin|operations — may hold.
 */
class HrSurfacesTest extends TestCase
{
    use HiresAddEmployees;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    public function test_cafe_employee_without_any_role_reaches_hr_me_but_not_hr_admin(): void
    {
        $barista = User::factory()->create();
        $employee = $this->hireFor($barista);
        Sanctum::actingAs($barista, ['*']);

        $this->getJson('/api/v1/hr/me')->assertOk()->assertJsonPath('data.id', $employee->id);
        $this->getJson('/api/v1/hr/employees')->assertForbidden();
    }

    public function test_operations_user_without_hr_permissions_gets_403_on_hr_admin(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->getJson('/api/v1/hr/employees')->assertForbidden();
        $this->getJson('/api/v1/hr/me')->assertForbidden();
    }

    public function test_custom_role_with_hr_employees_view_lists_employees(): void
    {
        Role::create(['name' => 'hr_viewer', 'guard_name' => 'web'])->givePermissionTo('hr.employees.view');
        $viewer = User::factory()->create();
        $viewer->assignRole('hr_viewer');
        $this->hireFor(User::factory()->create());
        Sanctum::actingAs($viewer, ['*']);

        $this->getJson('/api/v1/hr/employees')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/hr/employees', [])->assertForbidden();
    }

    public function test_admin_holds_every_hr_permission(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/api/v1/hr/employees')->assertOk();
        $this->getJson('/api/v1/hr/org-units')->assertOk();
        $this->getJson('/api/v1/hr/alerts')->assertOk();
    }

    public function test_member_app_token_cannot_reach_hr(): void
    {
        $user = User::factory()->create();
        $user->assignRole('member');
        $this->hireFor($user);
        Sanctum::actingAs($user, ['member-app']);

        $this->getJson('/api/v1/hr/me')->assertForbidden();
    }

    public function test_signed_file_route_is_reachable_without_session(): void
    {
        Storage::fake('local');
        $employee = $this->hireFor(User::factory()->create());
        $document = app(DocumentVault::class)->store(
            $employee,
            DocumentType::factory()->create(),
            UploadedFile::fake()->createWithContent('cv.pdf', '%PDF-1.4 cv'),
            [],
            null,
        );

        $url = app(DocumentVault::class)->link($document, null)['url'];

        $this->get($url)->assertOk();
        $this->assertSame('%PDF-1.4 cv', $this->get($url)->getContent());
    }
}
