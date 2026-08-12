<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchControllerTest extends TestCase
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

    private function actingAsOperations(): User
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        return $operator;
    }

    public function test_admin_can_create_a_branch_and_is_active_defaults_true(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/branches', [
            'name' => ['ar' => 'الفرع الرئيسي', 'en' => 'Main Branch'],
            'city' => ['ar' => 'حلب', 'en' => 'Aleppo'],
            'timezone' => 'Asia/Damascus',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('branches', ['timezone' => 'Asia/Damascus', 'is_active' => 1]);
    }

    public function test_admin_can_create_a_branch_with_explicit_null_is_active_and_it_still_defaults_true(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/v1/admin/branches', [
            'name' => ['ar' => 'الفرع الرئيسي', 'en' => 'Main Branch'],
            'city' => ['ar' => 'حلب', 'en' => 'Aleppo'],
            'timezone' => 'Asia/Damascus',
            'is_active' => null,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('branches', ['timezone' => 'Asia/Damascus', 'is_active' => 1]);
    }

    public function test_admin_can_list_branches(): void
    {
        $this->actingAsAdmin();
        Branch::factory()->count(2)->create();

        $this->getJson('/api/v1/admin/branches')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_admin_can_show_a_branch(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $this->getJson("/api/v1/admin/branches/{$branch->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $branch->id);
    }

    public function test_admin_can_update_a_branch_and_gets_back_a_message(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $response = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/branches/{$branch->id}", [
            'name' => $branch->name,
            'city' => $branch->city,
            'timezone' => 'Asia/Riyadh',
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertExactJson(['message' => 'Branch updated.']);
        $this->assertSame('Asia/Riyadh', $branch->fresh()->timezone);
        $this->assertFalse($branch->fresh()->is_active);
    }

    public function test_admin_can_delete_a_branch(): void
    {
        $this->actingAsAdmin();
        $branch = Branch::factory()->create();

        $this->deleteJson("/api/v1/admin/branches/{$branch->id}")->assertNoContent();
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
    }

    public function test_operations_cannot_delete_a_branch(): void
    {
        $this->actingAsOperations();
        $branch = Branch::factory()->create();

        $this->deleteJson("/api/v1/admin/branches/{$branch->id}")->assertForbidden();
        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
    }

    public function test_a_member_cannot_access_branch_admin_routes(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->getJson('/api/v1/admin/branches')->assertForbidden();
    }
}
