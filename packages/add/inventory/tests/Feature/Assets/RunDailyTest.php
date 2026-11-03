<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Contracts\InventoryNotifier;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\Fixtures\RecordingNotifier;
use Add\Inventory\Tests\TestCase;

class RunDailyTest extends TestCase
{
    use BuildsInventory;

    public function test_it_announces_due_maintenance_and_ending_warranties_once_each(): void
    {
        $notifier = new RecordingNotifier;
        $this->app->instance(InventoryNotifier::class, $notifier);
        $this->travelTo('2026-10-24 07:00');

        $service = app(AssetService::class);
        $projector = $service->register($this->assetItem(), $this->place(), ['warranty_until' => '2026-11-10'])->first();
        $projector->maintenanceSchedules()->create(['title' => 'Filter', 'interval_months' => 3, 'next_due_on' => '2026-10-28']);
        $chair = $service->register($this->assetItem(), $this->place(), ['warranty_until' => '2027-06-01'])->first();
        $chair->maintenanceSchedules()->create(['title' => 'Later', 'interval_months' => 3, 'next_due_on' => '2026-12-01']);

        $this->artisan('inventory:run-daily')->assertSuccessful();
        $this->artisan('inventory:run-daily')->assertSuccessful();

        $this->assertSame([
            [
                'ability' => Abilities::ASSETS_MAINTENANCE,
                'key' => 'maintenance_due',
                'placeholders' => ['asset' => $projector->code, 'title' => 'Filter', 'date' => '2026-10-28'],
                'target' => ['target_type' => 'inventory_asset', 'target_id' => (string) $projector->id],
            ],
            [
                'ability' => Abilities::ASSETS_MAINTENANCE,
                'key' => 'warranty_ending',
                'placeholders' => ['asset' => $projector->code, 'date' => '2026-11-10'],
                'target' => ['target_type' => 'inventory_asset', 'target_id' => (string) $projector->id],
            ],
        ], $notifier->sent);
    }

    public function test_a_disposed_asset_is_not_announced(): void
    {
        $notifier = new RecordingNotifier;
        $this->app->instance(InventoryNotifier::class, $notifier);

        $service = app(AssetService::class);
        $asset = $service->register($this->assetItem(), $this->place(), ['warranty_until' => now()->addDays(3)->toDateString()])->first();
        $asset->maintenanceSchedules()->create(['title' => 'Filter', 'interval_months' => 3, 'next_due_on' => now()->toDateString()]);
        $service->dispose($asset, DisposalMethod::Scrapped, now());

        $this->artisan('inventory:run-daily')->assertSuccessful();

        $this->assertSame([], $notifier->sent);
    }
}
