<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Plan gets the same two-tier Admin/Public CRUD as Founder/Partner
 * (App\Http\Controllers\Api\V1\Admin\AdminResourceController /
 * Api\V1\Public\PublicResourceController).
 */
class PlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => ['en' => 'Flex Desk', 'ar' => 'مكتب مرن'],
            'is_subscription' => true,
            'price' => 50000,
            'pricing_currency' => 'SYP',
            'duration_days' => 30,
            'included_hours' => 40,
            'overage_rate' => 1500,
            'is_active' => true,
            'order' => 1,
        ], $overrides);
    }

    public function test_admin_can_create_list_show_update_and_delete_a_plan(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $createResponse = $this->postJson('/api/v1/admin/plans', $this->payload());

        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.name.en', 'Flex Desk');
        $createResponse->assertJsonPath('data.pricing_currency', 'SYP');

        $planId = $createResponse->json('data.id');

        $this->getJson('/api/v1/admin/plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/admin/plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.id', $planId);

        $updateResponse = $this->putJson("/api/v1/admin/plans/{$planId}", $this->payload([
            'price' => 60000,
            'is_active' => false,
        ]));

        $updateResponse->assertOk();
        $updateResponse->assertJsonPath('data.price', '60000.00');
        $updateResponse->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/v1/admin/plans/{$planId}")->assertNoContent();

        $this->assertDatabaseCount('plans', 0);
    }

    public function test_operations_can_manage_plans_too(): void
    {
        $operator = User::factory()->create();
        $operator->assignRole('operations');
        Sanctum::actingAs($operator, ['*']);

        $this->postJson('/api/v1/admin/plans', $this->payload())->assertCreated();
    }

    public function test_a_member_cannot_manage_plans(): void
    {
        $member = User::factory()->create();
        $member->assignRole('member');
        Sanctum::actingAs($member, ['*']);

        $this->postJson('/api/v1/admin/plans', $this->payload())->assertForbidden();
    }

    public function test_guests_are_rejected_from_admin_plan_routes(): void
    {
        $this->postJson('/api/v1/admin/plans', $this->payload())->assertUnauthorized();
    }

    /**
     * Regression for the bug documented alongside
     * database/migrations/2026_08_09_155541_backfill_null_defaults_on_founders_partners_community_members_plans.php:
     * `is_active`/`order` are nullable in StorePlanRequest; omitting either
     * used to leave the create response showing null instead of the real
     * DB default (true/0). Asserts against the create response body
     * directly, not a re-fetch.
     */
    public function test_creating_a_plan_without_is_active_or_order_returns_the_real_defaults_not_null(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $payload = $this->payload();
        unset($payload['is_active'], $payload['order']);

        $response = $this->postJson('/api/v1/admin/plans', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.is_active', true);
        $response->assertJsonPath('data.order', 0);
        $this->assertNotNull($response->json('data.is_active'));
        $this->assertNotNull($response->json('data.order'));

        $this->assertDatabaseHas('plans', [
            'id' => $response->json('data.id'),
            'is_active' => true,
            'order' => 0,
        ]);
    }

    /**
     * `pricing_currency` used to accept any size:3 string — a plan priced
     * in e.g. EUR would pass validation but CurrencyConversionService only
     * understands USD/SYP, silently producing no converted price ever.
     * Rule::enum(Currency::class) closes that gap at the validation layer.
     */
    public function test_creating_a_plan_with_an_unsupported_pricing_currency_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/plans', $this->payload(['pricing_currency' => 'EUR']));

        $response->assertStatus(422);
    }

    public function test_public_plan_listing_only_shows_active_plans(): void
    {
        Plan::factory()->create(['is_active' => true, 'order' => 2]);
        Plan::factory()->create(['is_active' => false, 'order' => 1]);

        $response = $this->getJson('/api/v1/plans');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertTrue($response->json('data.0.is_active'));
    }
}
