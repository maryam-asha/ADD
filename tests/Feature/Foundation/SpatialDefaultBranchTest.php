<?php

namespace Tests\Feature\Foundation;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpatialDefaultBranchTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $defaultBranch;
    protected Branch $secondBranch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);

        $this->defaultBranch = Branch::factory()->create([
            'is_default' => true,
            'name' => ['en' => 'Default Branch', 'ar' => 'الفرع الافتراضي'],
        ]);

        $this->secondBranch = Branch::factory()->create([
            'is_default' => false,
            'name' => ['en' => 'Second Branch', 'ar' => 'الفرع الثاني'],
        ]);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    /**
     * Test the registered ResolveDefaultBranch middleware across all scenarios
     */
    public function test_registered_resolve_default_branch_middleware_handles_all_cases(): void
    {
        $this->actingAsAdmin();

        $defaultId = $this->defaultBranch->id;
        $secondId = $this->secondBranch->id;

        Building::factory()->create(['branch_id' => $defaultId]);
        Building::factory()->create(['branch_id' => $secondId]);

        // Scenario 1: GET without branch_id -> returns all buildings across all branches (Spatial listing)
        $getAllResponse = $this->getJson('/api/v1/admin/buildings');
        $getAllResponse->assertOk();
        $this->assertCount(2, $getAllResponse->json('data'), 'Unfiltered GET returns buildings from all branches!');

        // Scenario 2: GET with ?branch_id=X -> filters correctly by the requested branch
        $filterResponse = $this->getJson("/api/v1/admin/buildings?branch_id={$secondId}");
        $filterResponse->assertOk();
        $this->assertCount(1, $filterResponse->json('data'));
        $this->assertSame($secondId, $filterResponse->json('data.0.branch_id'), 'GET with ?branch_id filters accurately!');

        // Scenario 3: GET with X-Branch-Id header -> filters correctly by the header branch
        $headerGetResponse = $this->withHeader('X-Branch-Id', (string) $secondId)
            ->getJson('/api/v1/admin/buildings');
        $headerGetResponse->assertOk();
        $this->assertCount(1, $headerGetResponse->json('data'));
        $this->assertSame($secondId, $headerGetResponse->json('data.0.branch_id'), 'GET with X-Branch-Id filters accurately!');

        // Scenario 4: POST with explicit branch_id in body -> saved with the explicit branch_id (Spatial forms)
        $postExplicitResponse = $this->postJson('/api/v1/admin/buildings', [
            'branch_id' => $secondId,
            'name' => ['en' => 'Building Two', 'ar' => 'المبنى الثاني'],
            'floor_count' => 5,
        ]);
        $postExplicitResponse->assertCreated();
        $this->assertSame($secondId, $postExplicitResponse->json('data.branch_id'), 'Explicit POST preserves branch_id!');
        $this->assertDatabaseHas('buildings', [
            'name->en' => 'Building Two',
            'branch_id' => $secondId,
        ]);

        // Scenario 5: POST with X-Branch-Id header -> uses the header value
        $postHeaderResponse = $this->withHeader('X-Branch-Id', (string) $secondId)
            ->postJson('/api/v1/admin/buildings', [
                'name' => ['en' => 'Building via Header', 'ar' => 'مبنى عبر الهيدر'],
                'floor_count' => 4,
            ]);
        $postHeaderResponse->assertCreated();
        $this->assertSame($secondId, $postHeaderResponse->json('data.branch_id'), 'Header POST sets branch_id!');
        $this->assertDatabaseHas('buildings', [
            'name->en' => 'Building via Header',
            'branch_id' => $secondId,
        ]);

        // Scenario 6: POST without branch_id -> automatically gets default branch (Non-spatial cleaned forms)
        $this->flushHeaders();
        $postDefaultResponse = $this->postJson('/api/v1/admin/buildings', [
            'name' => ['en' => 'Default Assigned Building', 'ar' => 'مبنى بالفرع الافتراضي'],
            'floor_count' => 2,
        ]);
        $postDefaultResponse->assertCreated();
        $this->assertSame($defaultId, $postDefaultResponse->json('data.branch_id'), 'Missing POST receives default branch!');
        $this->assertDatabaseHas('buildings', [
            'name->en' => 'Default Assigned Building',
            'branch_id' => $defaultId,
        ]);
    }
}
