<?php

namespace Tests\Feature\Membership;

use App\Domain\Identity\Models\User;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanModule;
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
            'price_points' => 500,
            'duration_days' => 30,
            'included_hours' => 40,
            'overage_rate_points' => 15,
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
        $createResponse->assertJsonPath('data.price_points', 500);

        $planId = $createResponse->json('data.id');

        $this->getJson('/api/v1/admin/plans')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/v1/admin/plans/{$planId}")
            ->assertOk()
            ->assertJsonPath('data.id', $planId);

        $updateResponse = $this->withHeader('lang', 'en')->putJson("/api/v1/admin/plans/{$planId}", $this->payload([
            'price_points' => 600,
            'is_active' => false,
        ]));

        $updateResponse->assertOk();
        $updateResponse->assertExactJson(['message' => 'Plan updated.']);
        $this->assertDatabaseHas('plans', [
            'id' => $planId,
            'price_points' => 600,
            'is_active' => false,
        ]);

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
     * Replaces the old `pricing_currency` validation test — plans are now
     * denominated in whole ADD Points, so there is no per-plan currency to
     * validate. The equivalent boundary invariant is that a point price
     * must be a whole number: `integer` is what rejects a fractional one
     * at the request boundary rather than silently truncating it at charge
     * time (2026-09-12 add-point-system design, Invariant 2).
     */
    public function test_creating_a_plan_with_a_fractional_point_price_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/v1/admin/plans', $this->payload(['price_points' => 12.5]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('price_points');
    }

    public function test_admin_can_create_and_update_plan_with_module_balances_and_features(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $module = PlanModule::create([
            'name' => ['en' => 'Flexi Desk', 'ar' => 'مكتب مرن'],
            'slug' => 'flexi_desk',
        ]);

        $payload = $this->payload([
            'plan_module_id' => $module->id,
            'subtitle' => ['en' => 'Flexible work hours', 'ar' => 'ساعات عمل مرنة'],
            'balances' => [
                [
                    'category' => 'cafe',
                    'amount_points' => 400,
                    'description' => ['en' => 'Cafe grant', 'ar' => 'رصيد كافيه'],
                    'order' => 1,
                ],
                [
                    'category' => 'printing_internet',
                    'amount_points' => 250,
                    'description' => ['en' => 'Printing grant', 'ar' => 'رصيد طباعة'],
                    'order' => 2,
                ],
            ],
            'features' => [
                [
                    'name' => ['en' => 'Meeting Rooms', 'ar' => 'غرف اجتماعات'],
                    'value' => ['en' => '8 Hours', 'ar' => '8 ساعات'],
                    'icon' => 'clock',
                    'is_included' => true,
                    'order' => 1,
                ],
            ],
        ]);

        $createResponse = $this->postJson('/api/v1/admin/plans', $payload);
        $createResponse->assertCreated();
        $createResponse->assertJsonPath('data.plan_module_id', $module->id);
        $createResponse->assertJsonPath('data.subtitle.en', 'Flexible work hours');
        $createResponse->assertJsonCount(2, 'data.balances');
        $createResponse->assertJsonCount(1, 'data.features');
        $createResponse->assertJsonPath('data.balances.0.category', 'cafe');
        $createResponse->assertJsonPath('data.balances.0.amount_points', 400);
        $createResponse->assertJsonPath('data.features.0.value.en', '8 Hours');

        $planId = $createResponse->json('data.id');

        // Update features and balances
        $updatePayload = $this->payload([
            'plan_module_id' => $module->id,
            'balances' => [
                [
                    'category' => 'cafe',
                    'amount_points' => 600,
                    'description' => ['en' => 'Updated Cafe grant', 'ar' => 'رصيد كافيه معدل'],
                ],
            ],
            'features' => [
                [
                    'name' => ['en' => 'Unlimited Coffee', 'ar' => 'قهوة غير محدودة'],
                    'value' => null,
                    'icon' => 'coffee',
                ],
                [
                    'name' => ['en' => 'Access to Locker', 'ar' => 'خزانة شخصية'],
                    'value' => null,
                    'icon' => 'lock',
                ],
            ],
        ]);

        $updateResponse = $this->putJson("/api/v1/admin/plans/{$planId}", $updatePayload);
        $updateResponse->assertOk();

        $showResponse = $this->getJson("/api/v1/admin/plans/{$planId}");
        $showResponse->assertOk();
        $showResponse->assertJsonCount(1, 'data.balances');
        $showResponse->assertJsonPath('data.balances.0.amount_points', 600);
        $showResponse->assertJsonCount(2, 'data.features');
        $showResponse->assertJsonPath('data.features.0.name.en', 'Unlimited Coffee');
        $showResponse->assertJsonPath('data.features.1.name.en', 'Access to Locker');
    }
}
