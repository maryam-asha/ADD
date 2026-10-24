<?php

namespace Add\Inventory\Tests\Feature\Catalog;

use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;

class CatalogTest extends TestCase
{
    use BuildsInventory;

    private const MANAGE = [Abilities::CATALOG_VIEW, Abilities::CATALOG_MANAGE];

    public function test_a_child_category_takes_its_parents_kind(): void
    {
        $this->actingAsWith(self::MANAGE);
        $root = $this->postJson('/api/inventory/categories', [
            'name' => ['ar' => 'مستهلكات', 'en' => 'Consumables'],
            'kind' => 'stock',
        ])->assertCreated()->json('data');

        $this->postJson('/api/inventory/categories', ['name' => ['ar' => 'ورق'], 'parent_id' => $root['id']])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'stock')
            ->assertJsonPath('data.parent_id', $root['id']);

        $this->postJson('/api/inventory/categories', ['name' => ['ar' => 'شاشات'], 'parent_id' => $root['id'], 'kind' => 'asset'])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::catalog.kind_follows_parent'));
    }

    public function test_a_category_cannot_move_under_its_own_branch(): void
    {
        $this->actingAsWith(self::MANAGE);
        $root = Category::factory()->create();
        $child = Category::factory()->under($root)->create();

        $this->putJson("/api/inventory/categories/{$root->id}", ['parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::catalog.parent_in_subtree'));
    }

    public function test_a_roots_kind_changes_its_whole_branch_while_it_holds_no_items(): void
    {
        $this->actingAsWith(self::MANAGE);
        $root = Category::factory()->create();
        $child = Category::factory()->under($root)->create();

        $this->putJson("/api/inventory/categories/{$root->id}", ['kind' => 'stock'])->assertOk();
        $this->assertSame('stock', $child->refresh()->kind->value);

        Item::factory()->create(['category_id' => $child->id]);

        $this->putJson("/api/inventory/categories/{$root->id}", ['kind' => 'asset'])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::catalog.kind_has_items'));
    }

    public function test_a_field_key_is_unique_along_its_branch(): void
    {
        $this->actingAsWith(self::MANAGE);
        $root = Category::factory()->create();
        $child = Category::factory()->under($root)->create();

        $this->postJson("/api/inventory/categories/{$root->id}/fields", [
            'key' => 'brand',
            'label' => ['ar' => 'الماركة'],
            'type' => 'text',
        ])->assertCreated();

        $this->postJson("/api/inventory/categories/{$child->id}/fields", [
            'key' => 'brand',
            'label' => ['ar' => 'الماركة'],
            'type' => 'text',
        ])->assertUnprocessable()->assertJsonPath('message', __('inventory::catalog.key_taken', ['key' => 'brand']));
    }

    public function test_item_properties_answer_the_fields_of_the_category_and_its_ancestors(): void
    {
        $this->actingAsWith(self::MANAGE);
        $root = Category::factory()->create();
        $displays = Category::factory()->under($root)->create();
        $root->attributeDefinitions()->create(['key' => 'brand', 'label' => ['ar' => 'الماركة'], 'type' => 'text', 'is_required' => true]);
        $displays->attributeDefinitions()->create(['key' => 'panel', 'label' => ['ar' => 'اللوحة'], 'type' => 'select', 'options' => ['ips', 'oled']]);
        $displays->attributeDefinitions()->create(['key' => 'inches', 'label' => ['ar' => 'القياس'], 'type' => 'number']);
        $unit = Unit::factory()->create();

        $payload = fn (array $properties) => [
            'name' => ['ar' => 'شاشة 27', 'en' => '27-inch monitor'],
            'category_id' => $displays->id,
            'unit_id' => $unit->id,
            'properties' => $properties,
        ];

        $this->postJson('/api/inventory/items', $payload(['panel' => 'ips']))
            ->assertUnprocessable()->assertJsonValidationErrors('properties.brand');
        $this->postJson('/api/inventory/items', $payload(['brand' => 'Dell', 'panel' => 'tn']))
            ->assertUnprocessable()->assertJsonValidationErrors('properties.panel');
        $this->postJson('/api/inventory/items', $payload(['brand' => 'Dell', 'colour' => 'black']))
            ->assertUnprocessable()->assertJsonValidationErrors('properties');

        $item = $this->postJson('/api/inventory/items', $payload(['brand' => 'Dell', 'panel' => 'ips', 'inches' => 27]))
            ->assertCreated()
            ->assertJsonPath('data.kind', 'asset')
            ->assertJsonPath('data.properties.brand', 'Dell')
            ->json('data');

        $this->assertSame('ITM-'.str_pad((string) $item['id'], 5, '0', STR_PAD_LEFT), $item['sku']);

        $this->getJson("/api/inventory/categories/{$displays->id}")
            ->assertOk()
            ->assertJsonPath('data.fields.0.key', 'brand')
            ->assertJsonPath('data.fields.0.inherited', true)
            ->assertJsonPath('data.fields.1.inherited', false);
    }

    public function test_moving_an_item_rechecks_its_stored_properties(): void
    {
        $this->actingAsWith(self::MANAGE);
        $item = Item::factory()->create(['properties' => ['colour' => 'black']]);
        $strict = Category::factory()->create();
        $strict->attributeDefinitions()->create(['key' => 'brand', 'label' => ['ar' => 'الماركة'], 'type' => 'text', 'is_required' => true]);

        $this->putJson("/api/inventory/items/{$item->id}", ['category_id' => $strict->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['properties', 'properties.brand']);
    }

    public function test_an_item_with_history_keeps_its_kind_and_cannot_be_deleted(): void
    {
        $this->actingAsWith(self::MANAGE);
        $item = $this->assetItem();
        app(AssetService::class)->register($item, $this->place());

        $this->deleteJson("/api/inventory/items/{$item->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::catalog.item_has_history'));

        $this->putJson("/api/inventory/items/{$item->id}", ['category_id' => Category::factory()->stock()->create()->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::catalog.item_kind_locked'));
    }

    public function test_items_filter_by_category_branch_kind_and_search(): void
    {
        $this->actingAsWith([Abilities::CATALOG_VIEW]);
        $root = Category::factory()->create();
        $child = Category::factory()->under($root)->create();
        $inChild = Item::factory()->create(['category_id' => $child->id, 'sku' => 'MON-1']);
        Item::factory()->stock()->create(['sku' => 'PAPER-1']);

        $this->getJson("/api/inventory/items?category_id={$root->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inChild->id);
        $this->getJson('/api/inventory/items?kind=stock')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'PAPER-1');
        $this->getJson('/api/inventory/items?search=MON-1')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_packages_own_locations_are_managed_while_the_host_has_none(): void
    {
        $this->actingAsWith(self::MANAGE);
        $warehouse = $this->postJson('/api/inventory/locations', ['name' => ['ar' => 'المستودع']])->assertCreated()->json('data');
        $this->postJson('/api/inventory/locations', ['name' => ['ar' => 'الرف 1'], 'parent_id' => $warehouse['id']])->assertCreated();

        $this->getJson('/api/inventory/locations')->assertOk()->assertJsonCount(2, 'data');
        $this->deleteJson("/api/inventory/locations/{$warehouse['id']}")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::catalog.location_in_use'));
    }

    public function test_viewing_does_not_allow_managing(): void
    {
        $this->actingAsWith([Abilities::CATALOG_VIEW]);

        $this->getJson('/api/inventory/categories')->assertOk();
        $this->postJson('/api/inventory/categories', ['name' => ['ar' => 'x'], 'kind' => 'asset'])->assertForbidden();
    }
}
