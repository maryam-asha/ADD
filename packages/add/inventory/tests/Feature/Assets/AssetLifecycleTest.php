<?php

namespace Add\Inventory\Tests\Feature\Assets;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Models\Location;
use Add\Inventory\Contracts\CustodianResolver;
use Add\Inventory\Support\Place;
use Add\Inventory\Tests\Concerns\BuildsInventory;
use Add\Inventory\Tests\Fixtures\RecordingCustodians;
use Add\Inventory\Tests\Fixtures\User;
use Add\Inventory\Tests\TestCase;

class AssetLifecycleTest extends TestCase
{
    use BuildsInventory;

    private const ALL = [
        Abilities::ASSETS_VIEW,
        Abilities::ASSETS_MANAGE,
        Abilities::ASSETS_CUSTODY,
        Abilities::ASSETS_MAINTENANCE,
        Abilities::ASSETS_DISPOSE,
    ];

    private function registered(?Location $at = null): Asset
    {
        return app(AssetService::class)->register($this->assetItem(), $this->place($at))->first();
    }

    public function test_registering_several_gives_each_its_own_code_qr_and_first_movement(): void
    {
        $this->actingAsWith(self::ALL);
        $item = $this->assetItem();
        $room = Location::factory()->create();

        $assets = $this->postJson('/api/inventory/assets', [
            'item_id' => $item->id,
            'location' => $this->at($room),
            'count' => 3,
            'purchased_on' => '2026-10-01',
            'purchase_cost' => 150,
            'currency' => 'USD',
            'useful_life_months' => 36,
        ])->assertCreated()->json('data');

        $this->assertCount(3, $assets);
        $this->assertSame(['AST-000001', 'AST-000002', 'AST-000003'], array_column($assets, 'code'));
        $this->assertCount(3, array_unique(array_column($assets, 'qr_token')));
        $this->assertSame('in_service', $assets[0]['status']);
        $this->assertSame($room->id, $assets[0]['location']['id']);
        $this->assertSame('150', $assets[0]['purchase_cost']);
        $this->assertDatabaseCount('inv_asset_movements', 3);
    }

    public function test_a_stock_item_cannot_be_registered_as_an_asset(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets', ['item_id' => $this->stockItem()->id, 'location' => $this->at(Location::factory()->create())])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::assets.not_asset'));
    }

    public function test_an_unknown_location_is_refused(): void
    {
        $this->actingAsWith(self::ALL);

        $this->postJson('/api/inventory/assets', ['item_id' => $this->assetItem()->id, 'location' => ['type' => 'location', 'id' => 999]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location');
        $this->postJson('/api/inventory/assets', ['item_id' => $this->assetItem()->id, 'location' => ['type' => 'planet', 'id' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('location.type');
    }

    public function test_moving_records_where_it_came_from(): void
    {
        $this->actingAsWith(self::ALL);
        $from = Location::factory()->create();
        $to = Location::factory()->create();
        $asset = $this->registered($from);

        $this->postJson("/api/inventory/assets/{$asset->id}/move", ['location' => $this->at($to), 'note' => 'new room'])
            ->assertCreated()
            ->assertJsonPath('data.from.id', $from->id)
            ->assertJsonPath('data.to.id', $to->id);

        $this->postJson("/api/inventory/assets/{$asset->id}/move", ['location' => $this->at($to)])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::assets.already_there'));

        $this->getJson("/api/inventory/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.movements')
            ->assertJsonPath('data.location.id', $to->id);
    }

    public function test_custody_is_off_until_the_host_says_who_its_people_are(): void
    {
        $user = $this->actingAsWith(self::ALL);
        $asset = $this->registered();

        $this->postJson("/api/inventory/assets/{$asset->id}/assign", ['custodian' => ['type' => 'person', 'id' => $user->id]])
            ->assertUnprocessable();
    }

    public function test_custody_hands_over_once_and_comes_back(): void
    {
        $this->app->singleton(CustodianResolver::class, RecordingCustodians::class);
        $this->actingAsWith(self::ALL);
        $holder = User::query()->create(['name' => 'Rana', 'abilities' => []]);
        $asset = $this->registered();

        $this->postJson("/api/inventory/assets/{$asset->id}/assign", ['custodian' => ['type' => 'person', 'id' => $holder->id]])
            ->assertCreated()
            ->assertJsonPath('data.custodian.label', 'Rana');

        $this->postJson("/api/inventory/assets/{$asset->id}/assign", ['custodian' => ['type' => 'person', 'id' => $holder->id]])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::assets.already_assigned'));

        $this->getJson("/api/inventory/custody?custodian[type]=person&custodian[id]={$holder->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.asset.id', $asset->id);
        $this->getJson("/api/inventory/assets?custodian[type]=person&custodian[id]={$holder->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.custodian.label', 'Rana');

        $this->postJson("/api/inventory/assets/{$asset->id}/return", ['note' => 'leaving'])->assertOk();
        $this->postJson("/api/inventory/assets/{$asset->id}/return")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('inventory::assets.not_assigned'));

        $this->getJson("/api/inventory/custody?custodian[type]=person&custodian[id]={$holder->id}")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_maintenance_takes_it_out_of_service_and_closing_brings_it_back(): void
    {
        $this->app->singleton(CustodianResolver::class, RecordingCustodians::class);
        $this->travelTo('2026-11-01 09:00');
        $user = $this->actingAsWith(self::ALL);
        $asset = $this->registered();

        $schedule = $this->postJson("/api/inventory/assets/{$asset->id}/maintenance-schedules", [
            'title' => 'Lamp check',
            'interval_months' => 6,
            'next_due_on' => '2026-10-01',
        ])->assertCreated()->json('data');

        $record = $this->postJson("/api/inventory/assets/{$asset->id}/maintenance", [
            'type' => 'preventive',
            'description' => 'Six-monthly lamp check',
            'schedule_id' => $schedule['id'],
        ])->assertCreated()->json('data');

        $this->assertSame('in_maintenance', $asset->refresh()->status->value);
        $this->postJson("/api/inventory/assets/{$asset->id}/assign", ['custodian' => ['type' => 'person', 'id' => $user->id]])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.not_in_service'));
        $this->patchJson("/api/inventory/assets/{$asset->id}/status", ['status' => 'retired'])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.close_maintenance_first'));
        $this->postJson("/api/inventory/assets/{$asset->id}/maintenance", ['type' => 'corrective', 'description' => 'again'])
            ->assertUnprocessable();

        $this->travelTo('2026-11-20 10:00');
        $this->postJson("/api/inventory/maintenance/{$record['id']}/close", ['cost' => 40, 'currency' => 'USD', 'resolution' => 'Lamp replaced'])
            ->assertOk();

        $this->assertSame('in_service', $asset->refresh()->status->value);
        $this->getJson("/api/inventory/assets/{$asset->id}/maintenance-schedules")
            ->assertOk()->assertJsonPath('data.0.next_due_on', '2027-05-20');
        $this->postJson("/api/inventory/maintenance/{$record['id']}/close")
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.maintenance_closed'));
    }

    public function test_disposal_needs_it_back_and_ends_its_life(): void
    {
        $this->app->singleton(CustodianResolver::class, RecordingCustodians::class);
        $user = $this->actingAsWith(self::ALL);
        $asset = $this->registered();
        app(AssetService::class)->assign($asset, new Place('person', $user->id));

        $this->postJson("/api/inventory/assets/{$asset->id}/dispose", ['method' => 'sold', 'disposed_on' => now()->toDateString(), 'proceeds' => 30])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.return_first'));

        app(AssetService::class)->takeBack($asset);

        $this->postJson("/api/inventory/assets/{$asset->id}/dispose", ['method' => 'scrapped', 'disposed_on' => now()->toDateString(), 'proceeds' => 30])
            ->assertUnprocessable()->assertJsonValidationErrors('proceeds');
        $this->postJson("/api/inventory/assets/{$asset->id}/dispose", ['method' => 'sold', 'disposed_on' => now()->toDateString(), 'proceeds' => 30])
            ->assertOk();

        $this->getJson("/api/inventory/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'disposed')
            ->assertJsonPath('data.disposal.method', 'sold')
            ->assertJsonPath('data.disposal.proceeds', '30');
        $this->postJson("/api/inventory/assets/{$asset->id}/move", ['location' => $this->at(Location::factory()->create())])
            ->assertUnprocessable()->assertJsonPath('message', __('inventory::assets.disposed'));
    }

    public function test_status_takes_only_the_simple_ones(): void
    {
        $this->actingAsWith(self::ALL);
        $asset = $this->registered();

        $this->patchJson("/api/inventory/assets/{$asset->id}/status", ['status' => 'disposed'])->assertUnprocessable();
        $this->patchJson("/api/inventory/assets/{$asset->id}/status", ['status' => 'lost'])->assertOk();
        $this->assertSame('lost', $asset->refresh()->status->value);
    }

    public function test_a_qr_scan_opens_the_asset_and_ids_do_not(): void
    {
        $this->actingAsWith([Abilities::ASSETS_VIEW]);
        $asset = $this->registered();

        $this->getJson("/api/inventory/assets/qr/{$asset->qr_token}")->assertOk()->assertJsonPath('data.id', $asset->id);
        $this->getJson('/api/inventory/assets/qr/'.$asset->id)->assertNotFound();
    }

    public function test_listing_by_location_includes_the_places_inside_it(): void
    {
        $this->actingAsWith([Abilities::ASSETS_VIEW]);
        $building = Location::factory()->create();
        $room = Location::factory()->in($building)->create();
        $this->registered($room);
        $this->registered();

        $this->getJson("/api/inventory/assets?location[type]=location&location[id]={$building->id}")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/inventory/assets?location[type]=location&location[id]={$building->id}&within=0")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_salvage_cannot_exceed_cost_even_on_a_partial_edit(): void
    {
        $this->actingAsWith(self::ALL);
        $asset = $this->registered();
        $asset->update(['purchase_cost' => 100, 'currency' => 'USD']);

        $this->putJson("/api/inventory/assets/{$asset->id}", ['salvage_value' => 150])
            ->assertUnprocessable()->assertJsonValidationErrors('salvage_value');
        $this->putJson("/api/inventory/assets/{$asset->id}", ['salvage_value' => 10])->assertOk();
    }

    public function test_each_action_checks_its_own_ability(): void
    {
        $this->actingAsWith([Abilities::ASSETS_VIEW]);
        $asset = $this->registered();

        $this->postJson("/api/inventory/assets/{$asset->id}/move", ['location' => $this->at(Location::factory()->create())])->assertForbidden();
        $this->postJson("/api/inventory/assets/{$asset->id}/dispose", ['method' => 'scrapped', 'disposed_on' => now()->toDateString()])->assertForbidden();
        $this->postJson("/api/inventory/assets/{$asset->id}/maintenance", ['type' => 'corrective', 'description' => 'x'])->assertForbidden();
    }
}
