<?php

namespace Tests\Feature\Membership;

use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanDiscount;
use App\Domain\Membership\Models\PlanDiscountRedemption;
use App\Domain\Membership\Models\PlanModule;
use App\Domain\Membership\Models\ResourceType;
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

    /**
     * plan_discounts is a structured sibling of plan_balances/plan_features
     * (docs/decisions/plan-discounts-design.md) — same embedded
     * create/replace-on-update pattern.
     */
    public function test_admin_can_create_and_update_a_plan_with_discounts(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $space = Space::factory()->room()->create();

        $payload = $this->payload([
            'discounts' => [
                [
                    'percentage' => 30,
                    'space_id' => $space->id,
                    'description' => ['en' => '30% on this room', 'ar' => 'خصم 30% على هذه الغرفة'],
                    'usage_limit' => 3,
                    'order' => 1,
                ],
                [
                    'percentage' => 50,
                    'space_type' => SpaceType::EventHall->value,
                    'description' => ['en' => '50% on Event Spaces', 'ar' => 'خصم 50% على مساحات الفعاليات'],
                    'order' => 2,
                ],
            ],
        ]);

        $createResponse = $this->postJson('/api/v1/admin/plans', $payload);

        $createResponse->assertCreated();
        $createResponse->assertJsonCount(2, 'data.discounts');
        $createResponse->assertJsonPath('data.discounts.0.percentage', 30);
        $createResponse->assertJsonPath('data.discounts.0.space_id', $space->id);
        $createResponse->assertJsonPath('data.discounts.0.usage_limit', 3);
        $createResponse->assertJsonPath('data.discounts.1.space_type', 'event_hall');

        $planId = $createResponse->json('data.id');
        $this->assertDatabaseCount('plan_discounts', 2);

        $updateResponse = $this->putJson("/api/v1/admin/plans/{$planId}", $this->payload([
            'discounts' => [
                [
                    'percentage' => 20,
                    'space_type' => SpaceType::Room->value,
                    'order' => 1,
                ],
            ],
        ]));
        $updateResponse->assertOk();

        $showResponse = $this->getJson("/api/v1/admin/plans/{$planId}");
        $showResponse->assertJsonCount(1, 'data.discounts');
        $showResponse->assertJsonPath('data.discounts.0.percentage', 20);
        $showResponse->assertJsonPath('data.discounts.0.space_type', 'room');
        $this->assertDatabaseCount('plan_discounts', 1);
    }

    /**
     * plan_resource_grants is the natural-unit (not points) sibling of
     * plan_balances/plan_discounts (docs/decisions/resource-quota-design.md)
     * — same embedded create/replace-on-update pattern. `quantity` is a
     * decimal, not an integer, to support fractional grants like 5.5 hours
     * of internet.
     */
    public function test_admin_can_create_and_update_a_plan_with_resource_grants(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $printing = ResourceType::create([
            'slug' => 'printing',
            'name' => ['en' => 'Printing', 'ar' => 'طباعة'],
            'unit_label' => ['en' => 'page', 'ar' => 'صفحة'],
        ]);
        $internet = ResourceType::create([
            'slug' => 'internet',
            'name' => ['en' => 'Internet', 'ar' => 'إنترنت'],
            'unit_label' => ['en' => 'hour', 'ar' => 'ساعة'],
        ]);

        $payload = $this->payload([
            'resource_grants' => [
                [
                    'resource_type_id' => $printing->id,
                    'quantity' => 100,
                    'description' => ['en' => '100 pages', 'ar' => '100 صفحة'],
                    'order' => 1,
                ],
                [
                    'resource_type_id' => $internet->id,
                    'quantity' => 5.5,
                    'order' => 2,
                ],
            ],
        ]);

        $createResponse = $this->postJson('/api/v1/admin/plans', $payload);

        $createResponse->assertCreated();
        $createResponse->assertJsonCount(2, 'data.resource_grants');
        $createResponse->assertJsonPath('data.resource_grants.0.resource_type_id', $printing->id);
        $createResponse->assertJsonPath('data.resource_grants.0.quantity', 100);
        $createResponse->assertJsonPath('data.resource_grants.0.resource_type.slug', 'printing');
        $createResponse->assertJsonPath('data.resource_grants.1.resource_type_id', $internet->id);
        $createResponse->assertJsonPath('data.resource_grants.1.quantity', 5.5);

        $planId = $createResponse->json('data.id');
        $this->assertDatabaseCount('plan_resource_grants', 2);

        $updateResponse = $this->putJson("/api/v1/admin/plans/{$planId}", $this->payload([
            'resource_grants' => [
                [
                    'resource_type_id' => $internet->id,
                    'quantity' => 8.25,
                    'order' => 1,
                ],
            ],
        ]));
        $updateResponse->assertOk();

        $showResponse = $this->getJson("/api/v1/admin/plans/{$planId}");
        $showResponse->assertJsonCount(1, 'data.resource_grants');
        $showResponse->assertJsonPath('data.resource_grants.0.resource_type_id', $internet->id);
        $showResponse->assertJsonPath('data.resource_grants.0.quantity', 8.25);
        $this->assertDatabaseCount('plan_resource_grants', 1);
    }

    public function test_creating_a_plan_discount_with_both_space_id_and_space_type_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $space = Space::factory()->room()->create();

        $payload = $this->payload([
            'discounts' => [
                [
                    'percentage' => 30,
                    'space_id' => $space->id,
                    'space_type' => SpaceType::Room->value,
                ],
            ],
        ]);

        $response = $this->postJson('/api/v1/admin/plans', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['discounts.0.space_id']);
    }

    /**
     * A discount a booking was priced with is history: the redemption row
     * that records it must survive the plan being edited, or the booking
     * loses what its extension and check-out are priced from — and the
     * discount's usage allowance silently resets
     * (docs/decisions/plan-discounts-design.md).
     */
    public function test_updating_a_plan_deactivates_a_used_discount_instead_of_deleting_it(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $plan = Plan::factory()->create();
        $used = $plan->discounts()->create(['percentage' => 30, 'space_type' => SpaceType::Room]);
        $unused = $plan->discounts()->create(['percentage' => 10]);
        $redemption = $this->redeem($used, $plan);

        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->payload([
            'discounts' => [
                ['percentage' => 20, 'space_type' => SpaceType::Room->value],
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('plan_discounts', ['id' => $used->id, 'is_active' => false]);
        $this->assertDatabaseMissing('plan_discounts', ['id' => $unused->id]);
        $this->assertDatabaseHas('plan_discount_redemptions', ['id' => $redemption->id]);
        $this->assertDatabaseHas('plan_discounts', ['plan_id' => $plan->id, 'percentage' => 20, 'is_active' => true]);
    }

    public function test_updating_a_plan_discount_by_id_edits_it_in_place(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $plan = Plan::factory()->create();
        $discount = $plan->discounts()->create(['percentage' => 30, 'usage_limit' => 3]);
        $redemption = $this->redeem($discount, $plan);

        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->payload([
            'discounts' => [
                ['id' => $discount->id, 'percentage' => 25, 'usage_limit' => 5],
            ],
        ]))->assertOk();

        $this->assertDatabaseCount('plan_discounts', 1);
        $this->assertDatabaseHas('plan_discounts', ['id' => $discount->id, 'percentage' => 25, 'usage_limit' => 5, 'is_active' => true]);
        $this->assertDatabaseHas('plan_discount_redemptions', ['id' => $redemption->id]);
    }

    public function test_a_discount_id_from_another_plan_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Sanctum::actingAs($admin, ['*']);

        $plan = Plan::factory()->create();
        $foreign = Plan::factory()->create()->discounts()->create(['percentage' => 30]);

        $this->putJson("/api/v1/admin/plans/{$plan->id}", $this->payload([
            'discounts' => [
                ['id' => $foreign->id, 'percentage' => 25],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors(['discounts.0.id']);

        $this->assertDatabaseHas('plan_discounts', ['id' => $foreign->id, 'percentage' => 30]);
    }

    private function redeem(PlanDiscount $discount, Plan $plan): PlanDiscountRedemption
    {
        $member = User::factory()->create();
        $membership = Membership::factory()->create([
            'plan_id' => $plan->id,
            'owner_type' => OwnerType::User,
            'owner_id' => $member->id,
        ]);
        $booking = Booking::factory()->create(['user_id' => $member->id]);

        return PlanDiscountRedemption::create([
            'plan_discount_id' => $discount->id,
            'membership_id' => $membership->id,
            'booking_id' => $booking->id,
            'points_discounted' => 30,
        ]);
    }
}
