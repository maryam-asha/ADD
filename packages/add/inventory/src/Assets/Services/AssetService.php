<?php

namespace Add\Inventory\Assets\Services;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\DepreciationMethod;
use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\AssetAddition;
use Add\Inventory\Assets\Models\AssetAssignment;
use Add\Inventory\Assets\Models\AssetMovement;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Events\AssetDisposed;
use Add\Inventory\Events\AssetPlacedInService;
use Add\Inventory\Events\CapitalAdditionRecorded;
use Add\Inventory\Events\CapitalAdditionRemoved;
use Add\Inventory\Inventory;
use Add\Inventory\Support\InventoryException;
use Add\Inventory\Support\Place;
use Add\Inventory\Warehouses\Models\Warehouse;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An asset's life: registered somewhere, moved, handed to someone and
 * taken back, retired or lost, disposed of. Location and custody change
 * only here, each change leaving its row (inv_asset_movements,
 * inv_asset_assignments), with the asset row locked so two staff moving
 * or handing over the same laptop cannot both succeed.
 */
class AssetService
{
    /**
     * `$count` identical assets of one item at one place (five chairs
     * bought together), each with its own code and QR. A given `code` is
     * only for a single asset.
     *
     * @param  array<string, mixed>  $details
     * @return Collection<int, Asset>
     */
    public function register(Item $item, ?Place $location, array $details = [], int $count = 1, bool $draft = false): Collection
    {
        if (! $item->isAsset()) {
            InventoryException::fail('inventory::assets.not_asset');
        }

        if (! $item->is_active) {
            InventoryException::fail('inventory::assets.item_inactive');
        }

        if ($count < 1 || $count > (int) config('inventory.assets.max_batch')) {
            InventoryException::fail('inventory::assets.batch_size', ['max' => config('inventory.assets.max_batch')]);
        }

        if ($count > 1 && isset($details['code'])) {
            InventoryException::fail('inventory::assets.code_for_one');
        }

        // Not sent, or sent empty: the first method the package offers.
        $details['depreciation_method'] ??= DepreciationMethod::enabled()[0]->value;

        if ($item->isIntangible() && $location !== null) {
            InventoryException::fail('inventory::assets.intangible_not_in_place');
        }

        if (! $item->isIntangible()) {
            $this->ensureLocation($location ?? InventoryException::fail('inventory::assets.unknown_location'), $item);
        }

        return DB::transaction(function () use ($item, $location, $details, $count, $draft) {
            $assets = collect();

            for ($i = 0; $i < $count; $i++) {
                $asset = Asset::query()->create([
                    ...$details,
                    'item_id' => $item->id,
                    'qr_token' => Str::random(40),
                    'status' => $draft ? AssetStatus::Draft : AssetStatus::InService,
                    'location_type' => $location?->type,
                    'location_id' => $location?->id,
                ]);

                if (! $draft) {
                    $this->putInService($asset);
                }

                $assets->push($asset);
            }

            return $assets;
        });
    }

    /**
     * Several asset items put in one place together, all or none: each line
     * is `['item' => Item, 'count' => int, 'details' => [...]]`, and what the
     * purchase shares is laid under every line's own details.
     *
     * @param  list<array{item: Item, count: int, details: array<string, mixed>}>  $lines
     * @param  array<string, mixed>  $shared
     * @return Collection<int, Asset>
     */
    public function registerBatch(?Place $location, array $lines, array $shared = [], bool $draft = false): Collection
    {
        // An intangible line sits nowhere; every other one at the place.
        return DB::transaction(fn () => collect($lines)->flatMap(
            fn (array $line) => $this->register($line['item'], $line['item']->isIntangible() ? null : $location, [...$shared, ...$line['details']], $line['count'], $draft)
        )->values());
    }

    /**
     * A draft approved into service (config inventory.assets.drafts).
     */
    public function approve(Asset $asset): Asset
    {
        return DB::transaction(function () use ($asset) {
            $asset = $this->lock($asset);

            if ($asset->status !== AssetStatus::Draft) {
                InventoryException::fail('inventory::assets.not_draft');
            }

            $asset->update(['status' => AssetStatus::InService]);
            $this->putInService($asset);

            return $asset;
        });
    }

    /**
     * Only a draft is deleted: it never existed for anyone but its author.
     */
    public function deleteDraft(Asset $asset): void
    {
        DB::transaction(function () use ($asset) {
            $asset = $this->lock($asset);

            if ($asset->status !== AssetStatus::Draft) {
                InventoryException::fail('inventory::assets.only_drafts_deleted');
            }

            $asset->delete();
        });
    }

    /**
     * What an asset takes on entering service: its readable code (unless
     * one was given) and its arrival at its place.
     */
    private function putInService(Asset $asset): void
    {
        if ($asset->code === null) {
            $asset->update(['code' => config('inventory.assets.code_prefix').str_pad((string) $asset->id, (int) config('inventory.assets.code_pad'), '0', STR_PAD_LEFT)]);
        }

        $location = $asset->location();

        if ($location !== null) {
            AssetMovement::query()->create([
                'asset_id' => $asset->id,
                'to_type' => $location->type,
                'to_id' => $location->id,
                'actor_id' => Inventory::actorId(),
                'moved_at' => now(),
            ]);
        }

        AssetPlacedInService::dispatch($asset);
    }

    /**
     * A capital addition (config inventory.assets.capital_additions): it
     * raises the asset's cost, in the asset's currency.
     *
     * @param  array{added_on: string, amount: int|float|string, description: string, reference?: ?string, attachments?: ?list<string>}  $data
     */
    public function addCapital(Asset $asset, array $data): AssetAddition
    {
        if (! config('inventory.assets.capital_additions')) {
            InventoryException::fail('inventory::assets.additions_off');
        }

        return DB::transaction(function () use ($asset, $data) {
            $asset = $this->lock($asset);
            $this->ensureNotDisposed($asset);

            if ($asset->purchase_cost === null) {
                InventoryException::fail('inventory::assets.addition_needs_cost');
            }

            $addition = $asset->additions()->create([...$data, 'actor_id' => Inventory::actorId()]);
            CapitalAdditionRecorded::dispatch($addition);

            return $addition;
        });
    }

    public function removeCapital(AssetAddition $addition): void
    {
        DB::transaction(function () use ($addition) {
            $this->lock($addition->asset);
            $addition->delete();
            CapitalAdditionRemoved::dispatch($addition->id, $addition->asset_id, $addition->amount, $addition->added_on->toDateString());
        });
    }

    public function move(Asset $asset, Place $to, ?string $note = null, ?CarbonInterface $movedAt = null): AssetMovement
    {
        if ($asset->item->isIntangible()) {
            InventoryException::fail('inventory::assets.intangible_has_no_place');
        }

        $this->ensureLocation($to, $asset->item);

        return DB::transaction(function () use ($asset, $to, $note, $movedAt) {
            $asset = $this->lock($asset);
            $this->ensureNotDisposed($asset);

            if ($asset->location()->is($to)) {
                InventoryException::fail('inventory::assets.already_there');
            }

            $from = $asset->location();
            $asset->update(['location_type' => $to->type, 'location_id' => $to->id]);

            return AssetMovement::query()->create([
                'asset_id' => $asset->id,
                'from_type' => $from->type,
                'from_id' => $from->id,
                'to_type' => $to->type,
                'to_id' => $to->id,
                'note' => $note,
                'actor_id' => Inventory::actorId(),
                'moved_at' => $movedAt ?? now(),
            ]);
        });
    }

    /**
     * In service, retired or lost. Maintenance and disposal have their own
     * flows, and an asset under repair comes back by closing the repair.
     */
    public function setStatus(Asset $asset, AssetStatus $status): Asset
    {
        if (! in_array($status, AssetStatus::settable(), true)) {
            InventoryException::fail('inventory::assets.status_not_settable');
        }

        return DB::transaction(function () use ($asset, $status) {
            $asset = $this->lock($asset);
            $this->ensureNotDisposed($asset);

            if ($asset->status === AssetStatus::InMaintenance) {
                InventoryException::fail('inventory::assets.close_maintenance_first');
            }

            $asset->update(['status' => $status]);

            return $asset;
        });
    }

    public function assign(Asset $asset, Place $custodian, ?string $note = null, ?CarbonInterface $assignedAt = null): AssetAssignment
    {
        $custodians = Inventory::custodians();

        if ($custodians->types() === []) {
            InventoryException::fail('inventory::assets.custody_unavailable');
        }

        if (! in_array($custodian->type, $custodians->types(), true) || ! $custodians->exists($custodian)) {
            InventoryException::fail('inventory::assets.unknown_custodian');
        }

        return DB::transaction(function () use ($asset, $custodian, $note, $assignedAt) {
            $asset = $this->lock($asset);

            if ($asset->status !== AssetStatus::InService) {
                InventoryException::fail('inventory::assets.not_in_service');
            }

            if ($asset->currentAssignment()->exists()) {
                InventoryException::fail('inventory::assets.already_assigned');
            }

            return AssetAssignment::query()->create([
                'asset_id' => $asset->id,
                'custodian_type' => $custodian->type,
                'custodian_id' => $custodian->id,
                'assigned_at' => $assignedAt ?? now(),
                'assigned_by' => Inventory::actorId(),
                'note' => $note,
            ]);
        });
    }

    public function takeBack(Asset $asset, ?string $note = null, ?CarbonInterface $returnedAt = null): AssetAssignment
    {
        return DB::transaction(function () use ($asset, $note, $returnedAt) {
            $asset = $this->lock($asset);
            $assignment = $asset->currentAssignment()->first();

            if ($assignment === null) {
                InventoryException::fail('inventory::assets.not_assigned');
            }

            $returnedAt ??= now();

            if ($returnedAt->lt($assignment->assigned_at)) {
                InventoryException::fail('inventory::assets.returned_before_assigned');
            }

            $assignment->update([
                'returned_at' => $returnedAt,
                'returned_by' => Inventory::actorId(),
                'return_note' => $note,
            ]);

            return $assignment;
        });
    }

    /**
     * The end of an asset. It must be back from custody and out of repair
     * first; its maintenance schedules stop.
     */
    public function dispose(Asset $asset, DisposalMethod $method, CarbonInterface $on, int|float|string|null $proceeds = null, ?string $note = null): Asset
    {
        return DB::transaction(function () use ($asset, $method, $on, $proceeds, $note) {
            $asset = $this->lock($asset);
            $this->ensureNotDisposed($asset);

            if ($asset->currentAssignment()->exists()) {
                InventoryException::fail('inventory::assets.return_first');
            }

            if ($asset->openMaintenance()->exists()) {
                InventoryException::fail('inventory::assets.close_maintenance_first');
            }

            if ($asset->purchased_on !== null && $on->lt($asset->purchased_on)) {
                InventoryException::fail('inventory::assets.disposed_before_purchase');
            }

            $asset->update([
                'status' => AssetStatus::Disposed,
                'disposed_on' => $on,
                'disposal_method' => $method,
                'disposal_proceeds' => $method === DisposalMethod::Sold ? $proceeds : null,
                'disposal_note' => $note,
            ]);
            $asset->maintenanceSchedules()->update(['is_active' => false]);
            AssetDisposed::dispatch($asset);

            return $asset;
        });
    }

    private function lock(Asset $asset): Asset
    {
        return Asset::query()->lockForUpdate()->findOrFail($asset->id);
    }

    private function ensureNotDisposed(Asset $asset): void
    {
        if ($asset->status === AssetStatus::Draft) {
            InventoryException::fail('inventory::assets.draft');
        }

        if ($asset->isDisposed()) {
            InventoryException::fail('inventory::assets.disposed');
        }
    }

    /**
     * A host place, or an active warehouse whose scope lets it hold the item
     * (when assets may be stored in warehouses).
     */
    private function ensureLocation(Place $location, Item $item): void
    {
        if (! Inventory::assetPlaces()->exists($location)) {
            InventoryException::fail('inventory::assets.unknown_location');
        }

        if ($location->type === Warehouse::TYPE) {
            $warehouse = Warehouse::query()->findOrFail($location->id);

            if (! $warehouse->allows($item)) {
                InventoryException::fail('inventory::stock.outside_scope', ['warehouse' => (string) $warehouse->translate('name')]);
            }
        }
    }
}
