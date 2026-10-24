<?php

namespace Add\Inventory\Database\Factories;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Integrations\OwnLocationResolver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A bare asset row, skipping AssetService (no registration movement). Tests
 * of an asset's life register through the service instead.
 *
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'code' => 'AST-'.fake()->unique()->numberBetween(1, 999999),
            'qr_token' => Str::random(40),
            'status' => AssetStatus::InService,
            'location_type' => OwnLocationResolver::TYPE,
            'location_id' => Location::factory(),
        ];
    }
}
