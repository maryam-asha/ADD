<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\DepreciationCalculator;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * An asset's depreciation starts when it is put in service, which may be
 * later than its purchase; without that date it starts at the purchase.
 * An asset also keeps several attachments rather than one.
 */
class InServiceDateTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [Abilities::ASSETS_VIEW, Abilities::ASSETS_MANAGE];

    private function depreciation(array $attributes, string $asOf): ?array
    {
        $asset = Asset::factory()->create(['purchase_cost' => 1200, 'useful_life_months' => 12, ...$attributes]);

        return app(DepreciationCalculator::class)->for($asset->refresh(), CarbonImmutable::parse($asOf));
    }

    public function test_depreciation_starts_from_the_in_service_date(): void
    {
        $result = $this->depreciation(['purchased_on' => '2026-01-01', 'in_service_on' => '2026-03-01'], '2026-06-01');

        $this->assertSame(3, $result['months_elapsed']);
        $this->assertSame('300', $result['accumulated']);
    }

    public function test_without_an_in_service_date_it_starts_from_purchase(): void
    {
        $result = $this->depreciation(['purchased_on' => '2026-01-01'], '2026-06-01');

        $this->assertSame(5, $result['months_elapsed']);
    }

    public function test_an_in_service_date_after_today_has_no_depreciation_yet(): void
    {
        $result = $this->depreciation(['purchased_on' => '2026-01-01', 'in_service_on' => '2026-09-01'], '2026-06-01');

        $this->assertSame(0, $result['months_elapsed']);
        $this->assertSame('1200', $result['book_value']);
    }

    public function test_in_service_cannot_precede_purchase(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets', [
            'item_id' => $this->assetItem()->id,
            'location' => $this->at(Location::factory()->create()),
            'purchased_on' => '2026-03-01',
            'in_service_on' => '2026-02-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('in_service_on');

        $this->postJson('/api/inventory/assets/batch', [
            'location' => $this->at(Location::factory()->create()),
            'purchased_on' => '2026-03-01',
            'in_service_on' => '2026-02-01',
            'lines' => [['item_id' => $this->assetItem()->id]],
        ])->assertUnprocessable()->assertJsonValidationErrors('in_service_on');
    }

    public function test_the_in_service_date_is_registered_and_shown(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets/batch', [
            'location' => $this->at(Location::factory()->create()),
            'purchased_on' => '2026-03-01',
            'in_service_on' => '2026-03-15',
            'lines' => [['item_id' => $this->assetItem()->id]],
        ])->assertCreated()->assertJsonPath('data.0.in_service_on', '2026-03-15');
    }

    public function test_an_asset_keeps_several_attachments(): void
    {
        $this->actingAsWith(self::ALL);
        $files = ['/storage/uploads/inventory/invoice.pdf', 'https://cdn.example.test/photo.jpg'];

        $id = $this->postJson('/api/inventory/assets', [
            'item_id' => $this->assetItem()->id,
            'location' => $this->at(Location::factory()->create()),
            'attachments' => $files,
        ])->assertCreated()->assertJsonPath('data.0.attachments', $files)->json('data.0.id');

        $this->patchJson("/api/inventory/assets/{$id}", ['attachments' => array_fill(0, 11, '/storage/a.pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->patchJson("/api/inventory/assets/{$id}", ['attachments' => ['javascript:alert(1)']])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
    }

    public function test_an_existing_attachment_moves_into_the_list(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/2026_11_09_110000_add_in_service_on_and_attachments_to_inv_assets.php';
        $migration->down();
        $id = Asset::factory()->create()->id;
        DB::table('inv_assets')->where('id', $id)->update(['attachment_url' => '/storage/old.pdf']);

        $migration->up();

        $this->assertSame(['/storage/old.pdf'], Asset::query()->findOrFail($id)->attachments);
    }
}
