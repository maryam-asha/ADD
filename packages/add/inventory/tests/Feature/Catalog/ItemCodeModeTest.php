<?php

namespace Add\Inventory\Tests\Feature\Catalog;

use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Catalog\Models\Unit;
use Add\Inventory\Tests\TestCase;

/**
 * config inventory.items.sku_mode — who sets an item's code: `auto` (the
 * package, always), `on_create` (typed when the item is made, then fixed;
 * the default) or `editable` (typed and changed at any time).
 */
class ItemCodeModeTest extends TestCase
{
    private const ALL = [Abilities::CATALOG_VIEW, Abilities::CATALOG_MANAGE];

    /**
     * @return array<string, mixed>
     */
    private function payload(array $extra = []): array
    {
        return ['name' => ['ar' => 'ورق'], 'category_id' => Category::factory()->stock()->create()->id, 'unit_id' => Unit::factory()->create()->id, ...$extra];
    }

    public function test_by_default_a_code_is_typed_when_made_and_then_fixed(): void
    {
        $this->actingAsWith(self::ALL);

        $id = $this->postJson('/api/inventory/items', $this->payload(['sku' => 'CAF-001']))->assertCreated()->assertJsonPath('data.sku', 'CAF-001')->json('data.id');
        $this->patchJson("/api/inventory/items/{$id}", ['sku' => 'CAF-002'])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->assertSame('CAF-001', Item::query()->findOrFail($id)->sku);

        $this->postJson('/api/inventory/items', $this->payload())->assertCreated()->assertJsonPath('data.sku', fn (string $sku) => str_starts_with($sku, 'ITM-'));
        $this->getJson('/api/inventory/settings')->assertOk()->assertJsonPath('data.item_code_mode', 'on_create');
    }

    public function test_auto_never_takes_a_typed_code(): void
    {
        config(['inventory.items.sku_mode' => 'auto']);
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/items', $this->payload(['sku' => 'CAF-001']))->assertUnprocessable()->assertJsonValidationErrors('sku');
        $id = $this->postJson('/api/inventory/items', $this->payload())->assertCreated()->json('data.id');
        $this->patchJson("/api/inventory/items/{$id}", ['sku' => 'X-1'])->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->getJson('/api/inventory/settings')->assertOk()->assertJsonPath('data.item_code_mode', 'auto');
    }

    public function test_editable_lets_a_code_change_later(): void
    {
        config(['inventory.items.sku_mode' => 'editable']);
        $this->actingAsWith(self::ALL);

        $id = $this->postJson('/api/inventory/items', $this->payload(['sku' => 'CAF-001']))->assertCreated()->json('data.id');
        $this->patchJson("/api/inventory/items/{$id}", ['sku' => 'CAF-002'])->assertOk();
        $this->assertSame('CAF-002', Item::query()->findOrFail($id)->sku);
    }

    public function test_an_unknown_mode_falls_back_to_the_default(): void
    {
        config(['inventory.items.sku_mode' => 'sometimes']);
        $this->actingAsWith(self::ALL);

        $this->getJson('/api/inventory/settings')->assertOk()->assertJsonPath('data.item_code_mode', 'on_create');
    }
}
