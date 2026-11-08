<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\DepreciationCalculator;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Settings\SettingsStore;
use Add\Inventory\Tests\TestCase;
use Carbon\CarbonImmutable;

/**
 * config inventory.assets.capital_additions — money spent on an asset that
 * raises its cost (an upgrade, a rebuild), depreciated on a straight line
 * over what is left of the asset's life from the day it was added.
 */
class CapitalAdditionTest extends TestCase
{
    private const ALL = [Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE];

    private function asset(array $attributes = []): Asset
    {
        return Asset::factory()->create([
            'purchase_cost' => 1200, 'currency' => 'USD', 'useful_life_months' => 12, 'purchased_on' => '2026-01-01', ...$attributes,
        ]);
    }

    private function enable(): void
    {
        app(SettingsStore::class)->save(['assets.capital_additions' => true]);
    }

    public function test_additions_need_the_setting(): void
    {
        $this->actingAsWith(self::ALL);
        $asset = $this->asset();
        $payload = ['added_on' => '2026-02-01', 'amount' => 100, 'description' => 'ترقية الذاكرة'];

        $this->postJson("/api/inventory/assets/{$asset->id}/additions", $payload)
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.additions_off'));

        $this->enable();
        $this->postJson("/api/inventory/assets/{$asset->id}/additions", [...$payload, 'reference' => 'INV-5', 'attachments' => ['/storage/a.pdf']])
            ->assertCreated()->assertJsonPath('data.amount', '100')->assertJsonPath('data.reference', 'INV-5');
        $this->getJson("/api/inventory/assets/{$asset->id}/additions")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/inventory/assets/{$asset->id}")->assertJsonPath('data.additions_total', '100')->assertJsonPath('data.total_cost', '1300');
        $this->getJson('/api/inventory/settings')->assertJsonPath('data.capital_additions', true);
    }

    public function test_an_addition_raises_cost_and_is_spread_over_the_remaining_life(): void
    {
        $this->enable();
        $asset = $this->asset();
        $asset->additions()->create(['added_on' => '2026-07-01', 'amount' => 600, 'description' => 'x']);

        $result = app(DepreciationCalculator::class)->for($asset->refresh(), CarbonImmutable::parse('2026-10-01'));

        // 9 months of the 1200 (900), and 3 of the addition's 6 remaining months (300).
        $this->assertSame('1200', $result['accumulated']);
        $this->assertSame('600', $result['book_value']);
        $this->assertSame('200', $result['monthly']);
    }

    public function test_an_addition_after_end_of_life_is_not_depreciated(): void
    {
        $this->enable();
        $asset = $this->asset();
        $asset->additions()->create(['added_on' => '2027-02-01', 'amount' => 300, 'description' => 'x']);

        $result = app(DepreciationCalculator::class)->for($asset->refresh(), CarbonImmutable::parse('2027-06-01'));

        $this->assertSame('1200', $result['accumulated']);
        $this->assertSame('300', $result['book_value']);
    }

    public function test_an_addition_is_dated_within_the_assets_life_so_far(): void
    {
        $this->actingAsWith(self::ALL);
        $this->enable();
        $asset = $this->asset(['in_service_on' => '2026-03-01']);

        $this->postJson("/api/inventory/assets/{$asset->id}/additions", ['added_on' => '2026-02-01', 'amount' => 1, 'description' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('added_on');
        $this->postJson("/api/inventory/assets/{$asset->id}/additions", ['added_on' => now()->addDay()->toDateString(), 'amount' => 1, 'description' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('added_on');
    }

    public function test_an_asset_without_a_cost_or_disposed_takes_no_addition(): void
    {
        $this->actingAsWith(self::ALL);
        $this->enable();
        $payload = ['added_on' => '2026-02-01', 'amount' => 1, 'description' => 'x'];

        $this->postJson("/api/inventory/assets/{$this->asset(['purchase_cost' => null])->id}/additions", $payload)
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.addition_needs_cost'));
        $this->postJson("/api/inventory/assets/{$this->asset(['status' => AssetStatus::Disposed])->id}/additions", $payload)
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.disposed'));
    }

    public function test_an_addition_can_be_removed(): void
    {
        $this->actingAsWith(self::ALL);
        $this->enable();
        $addition = $this->asset()->additions()->create(['added_on' => '2026-02-01', 'amount' => 50, 'description' => 'x']);

        $this->deleteJson("/api/inventory/asset-additions/{$addition->id}")->assertOk()->assertJsonStructure(['message']);
        $this->assertModelMissing($addition);
    }

    public function test_additions_cannot_be_turned_off_while_one_exists(): void
    {
        $this->enable();
        $this->asset()->additions()->create(['added_on' => '2026-02-01', 'amount' => 50, 'description' => 'x']);

        $this->expectExceptionMessage(__('inventory::settings.locked_additions_exist'));
        app(SettingsStore::class)->save(['assets.capital_additions' => false]);
    }
}
