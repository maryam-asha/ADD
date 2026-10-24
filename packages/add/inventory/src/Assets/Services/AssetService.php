<?php

namespace Add\Inventory\Assets\Services;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\AssetAssignment;
use Add\Inventory\Assets\Models\AssetMovement;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Support\InventoryException;
use Add\Inventory\Support\Place;
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
    public function register(Item $item, Place $location, array $details = [], int $count = 1): Collection
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

        $this->ensureLocation($location);

        return DB::transaction(function () use ($item, $location, $details, $count) {
            $assets = collect();

            for ($i = 0; $i < $count; $i++) {
                $asset = Asset::query()->create([
                    ...$details,
                    'item_id' => $item->id,
                    'qr_token' => Str::random(40),
                    'status' => AssetStatus::InService,
                    'location_type' => $location->type,
                    'location_id' => $location->id,
                ]);

                if ($asset->code === null) {
                    $asset->update(['code' => config('inventory.assets.code_prefix').str_pad((string) $asset->id, (int) config('inventory.assets.code_pad'), '0', STR_PAD_LEFT)]);
                }

                AssetMovement::query()->create([
                    'asset_id' => $asset->id,
                    'to_type' => $location->type,
                    'to_id' => $location->id,
                    'actor_id' => Inventory::actorId(),
                    'moved_at' => now(),
                ]);

                $assets->push($asset);
            }

            return $assets;
        });
    }

    public function move(Asset $asset, Place $to, ?string $note = null, ?CarbonInterface $movedAt = null): AssetMovement
    {
        $this->ensureLocation($to);

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

            return $asset;
        });
    }

    private function lock(Asset $asset): Asset
    {
        return Asset::query()->lockForUpdate()->findOrFail($asset->id);
    }

    private function ensureNotDisposed(Asset $asset): void
    {
        if ($asset->isDisposed()) {
            InventoryException::fail('inventory::assets.disposed');
        }
    }

    private function ensureLocation(Place $location): void
    {
        if (! Inventory::locations()->exists($location)) {
            InventoryException::fail('inventory::assets.unknown_location');
        }
    }
}
