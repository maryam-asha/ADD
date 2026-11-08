<?php

namespace Add\Inventory\Settings;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\DepreciationMethod;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\AssetAddition;
use Add\Inventory\Catalog\Enums\SkuMode;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Services\ItemCodeSequence;
use Add\Inventory\Inventory;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Warehouses\Models\Warehouse;

/**
 * Every setting the dashboard may change, and when each is locked. What
 * ties the package to its host (user_model, place_types, the currency
 * table) stays in the config file only: that is the developer's, not the
 * user's.
 */
final class SettingsRegistry
{
    /**
     * @return array<string, SettingDefinition>
     */
    public static function definitions(): array
    {
        $numbered = fn () => app(ItemCodeSequence::class)->used() ? 'inventory::settings.locked_items_numbered' : null;
        $assetsExist = fn () => Asset::query()->exists() ? 'inventory::settings.locked_assets_exist' : null;

        $definitions = [
            new SettingDefinition('items.sku_mode', 'items', 'enum', ['required', 'string'], array_column(SkuMode::cases(), 'value')),
            new SettingDefinition('items.sku_prefix', 'items', 'string', ['present', 'nullable', 'string', 'max:20'], lockedWhen: $numbered),
            new SettingDefinition('items.sku_pad', 'items', 'integer', ['required', 'integer', 'min:1', 'max:12'], lockedWhen: $numbered),

            new SettingDefinition('assets.code_prefix', 'assets', 'string', ['present', 'nullable', 'string', 'max:20'], lockedWhen: $assetsExist),
            new SettingDefinition('assets.code_pad', 'assets', 'integer', ['required', 'integer', 'min:1', 'max:12'], lockedWhen: $assetsExist),
            new SettingDefinition('assets.store_in_warehouses', 'assets', 'boolean', ['required', 'boolean'], lockedWhen: fn ($current, $next) => $next === false
                && Asset::query()->where('location_type', Warehouse::TYPE)->exists() ? 'inventory::settings.locked_assets_in_warehouses' : null),
            new SettingDefinition('assets.drafts', 'assets', 'boolean', ['required', 'boolean'], lockedWhen: fn ($current, $next) => $next === false
                && Asset::query()->where('status', AssetStatus::Draft)->exists() ? 'inventory::settings.locked_drafts_exist' : null),
            new SettingDefinition('assets.max_batch', 'assets', 'integer', ['required', 'integer', 'min:1', 'max:1000']),
            new SettingDefinition('assets.max_lines', 'assets', 'integer', ['required', 'integer', 'min:1', 'max:200']),
            new SettingDefinition('assets.maintenance_lead_days', 'assets', 'integer', ['required', 'integer', 'min:0', 'max:365']),
            new SettingDefinition('assets.warranty_lead_days', 'assets', 'integer', ['required', 'integer', 'min:0', 'max:365']),

            new SettingDefinition('assets.intangible', 'depreciation', 'boolean', ['required', 'boolean'], lockedWhen: fn ($current, $next) => $next === false
                && Category::query()->where('intangible', true)->exists() ? 'inventory::settings.locked_intangible_exists' : null),
            new SettingDefinition('assets.capital_additions', 'depreciation', 'boolean', ['required', 'boolean'], lockedWhen: fn ($current, $next) => $next === false
                && AssetAddition::query()->exists() ? 'inventory::settings.locked_additions_exist' : null),
            new SettingDefinition('assets.depreciation_methods', 'depreciation', 'list', ['required', 'array', 'min:1'], array_column(DepreciationMethod::cases(), 'value'), lockedWhen: function ($current, $next) {
                $removed = is_array($next) ? array_diff((array) $current, $next) : [];

                return $removed !== [] && Asset::query()->whereIn('depreciation_method', $removed)->exists() ? 'inventory::settings.locked_method_in_use' : null;
            }),

            new SettingDefinition('stock.currency', 'stock', 'string', ['required', ...Inventory::currencyRules()], lockedWhen: fn () => StockMovement::query()->exists() ? 'inventory::settings.locked_stock_moved' : null),
        ];

        return collect($definitions)->keyBy('key')->all();
    }
}
