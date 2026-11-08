<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\AssetMovement;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Settings\SettingsStore;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

/**
 * config inventory.assets.drafts — an asset is saved as a draft (no code,
 * no movement, no depreciation, no action) and put in service only when
 * someone holding inventory.assets.approve approves it.
 */
class AssetDraftTest extends TestCase
{
    use BuildsInventory;

    private const MANAGE = [Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE, Abilities::ASSETS_MAINTENANCE, Abilities::REPORTS_VIEW];

    private function payload(array $extra = []): array
    {
        return ['item_id' => $this->assetItem()->id, 'location' => $this->at(Location::factory()->create()), 'purchase_cost' => 100, 'currency' => 'USD', 'purchased_on' => '2026-01-01', 'useful_life_months' => 10, ...$extra];
    }

    private function enable(): void
    {
        app(SettingsStore::class)->save(['assets.drafts' => true]);
    }

    public function test_drafts_need_the_setting(): void
    {
        $this->actingAsWith(self::MANAGE);

        $this->postJson('/api/inventory/assets', $this->payload(['draft' => true]))->assertUnprocessable()->assertJsonValidationErrors('draft');
        $this->enable();
        $this->getJson('/api/inventory/settings')->assertJsonPath('data.asset_drafts', true);
    }

    public function test_a_draft_has_no_code_movement_or_depreciation_until_approved(): void
    {
        $this->enable();
        $this->actingAsWith([...self::MANAGE, Abilities::ASSETS_APPROVE]);

        $draft = $this->postJson('/api/inventory/assets', $this->payload())->assertCreated()->json('data.0');

        $this->assertSame('draft', $draft['status']);
        $this->assertNull($draft['code']);
        $this->assertNull($draft['depreciation']);
        $this->assertSame(0, AssetMovement::query()->count());
        $this->getJson('/api/inventory/reports/asset-register')->assertOk()->assertJsonCount(0, 'data.assets');

        $this->postJson("/api/inventory/assets/{$draft['id']}/approve")->assertOk()->assertJsonStructure(['message']);

        $asset = Asset::query()->findOrFail($draft['id']);
        $this->assertSame(AssetStatus::InService, $asset->status);
        $this->assertNotNull($asset->code);
        $this->assertSame(1, AssetMovement::query()->count());
        $this->postJson("/api/inventory/assets/{$draft['id']}/approve")->assertUnprocessable();
    }

    public function test_approving_needs_the_approve_ability(): void
    {
        $this->enable();
        $this->actingAsWith(self::MANAGE);
        $id = $this->postJson('/api/inventory/assets', $this->payload())->assertCreated()->json('data.0.id');

        $this->postJson("/api/inventory/assets/{$id}/approve")->assertForbidden();
    }

    public function test_saving_approved_directly_needs_the_approve_ability(): void
    {
        $this->enable();
        $this->actingAsWith(self::MANAGE);
        $this->postJson('/api/inventory/assets', $this->payload(['draft' => false]))->assertForbidden();
        $this->postJson('/api/inventory/assets/batch', ['location' => $this->at(Location::factory()->create()), 'draft' => false, 'lines' => [['item_id' => $this->assetItem()->id]]])->assertForbidden();

        $this->actingAsWith([...self::MANAGE, Abilities::ASSETS_APPROVE]);
        $this->postJson('/api/inventory/assets', $this->payload(['draft' => false]))->assertCreated()->assertJsonPath('data.0.status', 'in_service');
        $this->postJson('/api/inventory/assets/batch', ['location' => $this->at(Location::factory()->create()), 'lines' => [['item_id' => $this->assetItem()->id, 'count' => 2]]])
            ->assertCreated()->assertJsonPath('data.1.status', 'draft');
    }

    public function test_a_draft_takes_no_action_and_only_drafts_are_deleted(): void
    {
        $this->enable();
        $this->actingAsWith([...self::MANAGE, Abilities::ASSETS_APPROVE]);
        $id = $this->postJson('/api/inventory/assets', $this->payload())->json('data.0.id');

        $this->postJson("/api/inventory/assets/{$id}/move", ['location' => $this->at(Location::factory()->create())])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.draft'));
        $this->patchJson("/api/inventory/assets/{$id}/status", ['status' => 'retired'])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.draft'));
        $this->postJson("/api/inventory/assets/{$id}/maintenance", ['type' => 'corrective', 'description' => 'x'])->assertUnprocessable();
        $this->patchJson("/api/inventory/assets/{$id}", ['serial_number' => 'S-9'])->assertOk();

        $live = $this->postJson('/api/inventory/assets', $this->payload(['draft' => false]))->json('data.0.id');
        $this->deleteJson("/api/inventory/assets/{$live}")->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.only_drafts_deleted'));
        $this->deleteJson("/api/inventory/assets/{$id}")->assertOk();
        $this->assertNull(Asset::query()->find($id));
    }

    public function test_drafts_cannot_be_turned_off_while_one_exists(): void
    {
        $this->enable();
        Asset::factory()->create(['status' => AssetStatus::Draft, 'code' => null]);

        $this->expectExceptionMessage(__('inventory::settings.locked_drafts_exist'));
        app(SettingsStore::class)->save(['assets.drafts' => false]);
    }
}
