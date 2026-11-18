<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Assets\Services\DepreciationCalculator;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Events\AssetDisposed;
use Add\Inventory\Events\AssetPlacedInService;
use Add\Inventory\Events\CapitalAdditionRecorded;
use Add\Inventory\Events\DepreciationMonthClosed;
use Add\Inventory\Settings\SettingsStore;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * What a host's ledger listens to: an asset placed in service, a capital
 * addition, a disposal, and the close of each month's depreciation. The
 * package keeps no accounts itself.
 */
class AccountingEventsTest extends TestCase
{
    use BuildsInventory;

    public function test_registering_in_service_announces_each_asset(): void
    {
        Event::fake([AssetPlacedInService::class]);
        $service = app(AssetService::class);

        $service->registerBatch($this->place(), [['item' => $this->assetItem(), 'count' => 3, 'details' => []]]);
        Event::assertDispatchedTimes(AssetPlacedInService::class, 3);

        $draft = $service->register($this->assetItem(), $this->place(), [], 1, true)->first();
        Event::assertDispatchedTimes(AssetPlacedInService::class, 3);

        $service->approve($draft);
        Event::assertDispatched(AssetPlacedInService::class, fn (AssetPlacedInService $event) => $event->asset->is($draft));
    }

    public function test_an_addition_and_a_disposal_are_announced(): void
    {
        Event::fake([CapitalAdditionRecorded::class, AssetDisposed::class]);
        app(SettingsStore::class)->save(['assets.capital_additions' => true]);
        $asset = Asset::factory()->create(['purchase_cost' => 100, 'currency' => 'USD', 'purchased_on' => '2026-01-01']);
        $service = app(AssetService::class);

        $service->addCapital($asset, ['added_on' => '2026-02-01', 'amount' => 10, 'description' => 'x']);
        $service->dispose($asset, DisposalMethod::cases()[0], CarbonImmutable::parse('2026-03-01'));

        Event::assertDispatched(CapitalAdditionRecorded::class);
        Event::assertDispatched(AssetDisposed::class, fn (AssetDisposed $event) => $event->asset->is($asset));
    }

    public function test_a_rolled_back_registration_announces_nothing(): void
    {
        Event::fake([AssetPlacedInService::class]);

        try {
            DB::transaction(function () {
                app(AssetService::class)->register($this->assetItem(), $this->place(Location::factory()->create()));
                throw new RuntimeException('the rest failed');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(AssetPlacedInService::class);
    }

    public function test_each_month_is_closed_once(): void
    {
        Event::fake([DepreciationMonthClosed::class]);

        $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00'));
        $this->artisan('inventory:run-daily')->assertSuccessful();
        $this->artisan('inventory:run-daily')->assertSuccessful();
        Event::assertDispatchedTimes(DepreciationMonthClosed::class, 1);
        Event::assertDispatched(DepreciationMonthClosed::class, fn (DepreciationMonthClosed $event) => $event->year === 2026 && $event->month === 11);

        $this->travelTo(CarbonImmutable::parse('2027-01-01 10:00'));
        $this->artisan('inventory:run-daily')->assertSuccessful();
        Event::assertDispatched(DepreciationMonthClosed::class, fn (DepreciationMonthClosed $event) => $event->year === 2026 && $event->month === 12);
        Event::assertDispatchedTimes(DepreciationMonthClosed::class, 2);
    }

    public function test_for_month_matches_the_running_total(): void
    {
        $asset = Asset::factory()->create(['purchase_cost' => 1000, 'useful_life_months' => 10, 'purchased_on' => '2026-01-01', 'depreciation_method' => 'declining_balance']);
        $calculator = app(DepreciationCalculator::class);

        $this->assertSame('200', $calculator->forMonth($asset, 2026, 1));
        $this->assertSame('160', $calculator->forMonth($asset, 2026, 2));
        $this->assertSame('0', $calculator->forMonth($asset, 2025, 12));
    }
}
