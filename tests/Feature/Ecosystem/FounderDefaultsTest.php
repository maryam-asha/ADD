<?php

namespace Tests\Feature\Ecosystem;

use App\Domain\Identity\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression for the bug documented alongside
 * database/migrations/2026_08_09_155541_backfill_null_defaults_on_founders_partners_community_members_plans.php:
 * `order` is `nullable` in `StoreFounderRequest` and the DB column defaults
 * to `0`, but `FounderController::store()` used to do
 * `Founder::create($request->validated())` directly — omitting `order`
 * meant the *response* showed `null` for it, even though the actual DB row
 * was correctly `0` (Eloquent doesn't re-fetch column defaults into an
 * unrefreshed model). This asserts against the create response body
 * itself, not a follow-up `fresh()`/re-fetch, since the whole bug was that
 * the immediate response was wrong.
 */
class FounderDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_creating_a_founder_without_order_returns_the_real_default_not_null(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/founders', [
            'name' => ['ar' => 'أحمد الشامي', 'en' => 'Ahmad Al-Shami'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.order', 0);
        $this->assertNotNull($response->json('data.order'));

        $this->assertDatabaseHas('founders', [
            'id' => $response->json('data.id'),
            'order' => 0,
        ]);
    }
}
