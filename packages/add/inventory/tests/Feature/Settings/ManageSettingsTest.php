<?php

namespace Add\Inventory\Tests\Feature\Settings;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Tests\TestCase;

/**
 * GET settings/manage lists every setting the page shows; PATCH settings
 * saves the values sent, behind inventory.settings.manage.
 */
class ManageSettingsTest extends TestCase
{
    public function test_the_page_lists_every_setting_with_its_lock(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE]);

        $row = fn () => collect($this->getJson('/api/inventory/settings/manage')->assertOk()->json('data'))->firstWhere('key', 'assets.code_prefix');

        $this->assertSame(['key' => 'assets.code_prefix', 'group' => 'assets', 'type' => 'string', 'options' => [], 'value' => 'AST-', 'default' => 'AST-', 'locked' => false, 'locked_reason' => null], $row());

        Asset::factory()->create();
        $this->assertTrue($row()['locked']);
        $this->assertSame(__('inventory::settings.locked_assets_exist'), $row()['locked_reason']);
    }

    public function test_saving_answers_a_message_and_the_public_settings_follow(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE]);

        $this->patchJson('/api/inventory/settings', ['values' => ['items.sku_mode' => 'auto', 'assets.max_batch' => '20']])
            ->assertOk()->assertExactJson(['message' => __('inventory::settings.saved')]);

        $this->getJson('/api/inventory/settings')->assertOk()->assertJsonPath('data.item_code_mode', 'auto');
        $this->assertSame(20, config('inventory.assets.max_batch'));
    }

    public function test_a_locked_or_invalid_change_names_the_key(): void
    {
        $this->actingAsWith([Abilities::SETTINGS_MANAGE]);
        Asset::factory()->create();

        $this->patchJson('/api/inventory/settings', ['values' => ['assets.code_prefix' => 'A-']])
            ->assertUnprocessable()->assertJsonValidationErrors(['values.assets.code_prefix' => __('inventory::settings.locked_assets_exist')]);
        $this->patchJson('/api/inventory/settings', ['values' => ['assets.max_batch' => 0]])
            ->assertUnprocessable()->assertJsonValidationErrors('values.assets.max_batch');
        $this->patchJson('/api/inventory/settings', [])
            ->assertUnprocessable()->assertJsonValidationErrors('values');
    }

    public function test_it_needs_the_settings_ability(): void
    {
        $this->actingAsWith([Abilities::CATALOG_VIEW]);

        $this->getJson('/api/inventory/settings/manage')->assertForbidden();
        $this->patchJson('/api/inventory/settings', ['values' => ['items.sku_mode' => 'auto']])->assertForbidden();
    }
}
