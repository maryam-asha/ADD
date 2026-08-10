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
 * `order`/`is_active` are `nullable` in `StorePartnerRequest`; omitting
 * either used to leave the create *response* showing `null` instead of the
 * real DB default (`0`/`true`), even though the row itself was correct.
 * Asserts against the create response body directly, not a re-fetch.
 */
class PartnerDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_creating_a_partner_without_order_or_is_active_returns_the_real_defaults_not_null(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/partners', [
            'name' => ['ar' => 'بنك سوريا', 'en' => 'Bank of Syria'],
            'category' => 'local',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.order', 0);
        $response->assertJsonPath('data.is_active', true);
        $this->assertNotNull($response->json('data.order'));
        $this->assertNotNull($response->json('data.is_active'));

        $this->assertDatabaseHas('partners', [
            'id' => $response->json('data.id'),
            'order' => 0,
            'is_active' => true,
        ]);
    }
}
