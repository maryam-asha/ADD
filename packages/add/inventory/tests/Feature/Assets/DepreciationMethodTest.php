<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\DepreciationCalculator;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Settings\SettingsStore;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * config inventory.assets.depreciation_methods — the methods an asset may
 * be depreciated by: straight line (the default, alone) and declining
 * balance (twice the straight-line rate on what is left, never below the
 * salvage value).
 */
class DepreciationMethodTest extends TestCase
{
    use BuildsInventory;

    private function declining(array $attributes, string $asOf): array
    {
        $asset = Asset::factory()->create([
            'purchase_cost' => 1000, 'useful_life_months' => 10, 'purchased_on' => '2026-01-01',
            'depreciation_method' => 'declining_balance', ...$attributes,
        ]);

        return app(DepreciationCalculator::class)->for($asset->refresh(), CarbonImmutable::parse($asOf));
    }

    public function test_straight_line_is_the_default(): void
    {
        $asset = Asset::factory()->create(['purchase_cost' => 1000, 'useful_life_months' => 10, 'purchased_on' => '2026-01-01']);

        $this->assertSame('straight_line', $asset->refresh()->depreciation_method->value);
        $this->assertSame('200', app(DepreciationCalculator::class)->for($asset, CarbonImmutable::parse('2026-03-01'))['accumulated']);
    }

    public function test_declining_balance_charges_more_early(): void
    {
        // Rate 2/10 = 20% of what is left each month: 200, then 160.
        $this->assertSame('200', $this->declining([], '2026-02-01')['accumulated']);
        $two = $this->declining([], '2026-03-01');
        $this->assertSame('360', $two['accumulated']);
        $this->assertSame('160', $two['monthly']);
        $this->assertSame('640', $two['book_value']);
    }

    public function test_declining_balance_stops_at_salvage(): void
    {
        // 1000 × 0.8^5 = 327.68; a sixth month would take it to 262.14 — held at 300.
        $this->assertSame('327.68', $this->declining(['salvage_value' => 300], '2026-06-01')['book_value']);
        $this->assertSame('300', $this->declining(['salvage_value' => 300], '2026-07-01')['book_value']);
        $end = $this->declining(['salvage_value' => 300], '2026-11-01');
        $this->assertSame('300', $end['book_value']);
        $this->assertTrue($end['fully_depreciated']);
    }

    public function test_a_method_not_enabled_is_refused(): void
    {
        $this->actingAsWith([Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE]);
        $payload = ['item_id' => $this->assetItem()->id, 'location' => $this->at(Location::factory()->create()), 'depreciation_method' => 'declining_balance'];

        $this->postJson('/api/inventory/assets', $payload)->assertUnprocessable()->assertJsonValidationErrors('depreciation_method');

        app(SettingsStore::class)->save(['assets.depreciation_methods' => ['straight_line', 'declining_balance']]);
        $this->postJson('/api/inventory/assets', $payload)->assertCreated()->assertJsonPath('data.0.depreciation_method', 'declining_balance');
        $this->getJson('/api/inventory/settings')->assertJsonPath('data.depreciation_methods', ['straight_line', 'declining_balance']);
    }

    public function test_a_method_in_use_cannot_be_removed(): void
    {
        app(SettingsStore::class)->save(['assets.depreciation_methods' => ['straight_line', 'declining_balance']]);
        Asset::factory()->create(['depreciation_method' => 'declining_balance']);

        $this->expectExceptionMessage(__('inventory::settings.locked_method_in_use'));
        app(SettingsStore::class)->save(['assets.depreciation_methods' => ['straight_line']]);
    }

    public function test_at_least_one_method_stays(): void
    {
        $this->expectException(ValidationException::class);
        app(SettingsStore::class)->save(['assets.depreciation_methods' => []]);
    }
}
