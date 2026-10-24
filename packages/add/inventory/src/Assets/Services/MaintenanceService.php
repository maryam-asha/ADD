<?php

namespace Add\Inventory\Assets\Services;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\MaintenanceType;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\MaintenanceRecord;
use Add\Inventory\Assets\Models\MaintenanceSchedule;
use Add\Inventory\Inventory;
use Add\Inventory\Support\InventoryException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Repairs and servicing. An open record keeps its asset `in_maintenance`
 * (it cannot be handed over, moved out of the record, or disposed of);
 * closing it puts the asset back in service and, for scheduled servicing,
 * sets the schedule's next date from the day the work was done.
 */
class MaintenanceService
{
    public function open(
        Asset $asset,
        MaintenanceType $type,
        string $description,
        ?string $vendor = null,
        ?MaintenanceSchedule $schedule = null,
        ?CarbonInterface $openedAt = null,
    ): MaintenanceRecord {
        if ($schedule !== null && $schedule->asset_id !== $asset->id) {
            InventoryException::fail('inventory::assets.schedule_of_another_asset');
        }

        return DB::transaction(function () use ($asset, $type, $description, $vendor, $schedule, $openedAt) {
            $asset = Asset::query()->lockForUpdate()->findOrFail($asset->id);

            if ($asset->status !== AssetStatus::InService) {
                InventoryException::fail('inventory::assets.not_in_service');
            }

            $record = MaintenanceRecord::query()->create([
                'asset_id' => $asset->id,
                'schedule_id' => $schedule?->id,
                'type' => $type,
                'description' => $description,
                'vendor' => $vendor,
                'opened_at' => $openedAt ?? now(),
                'opened_by' => Inventory::actorId(),
            ]);
            $asset->update(['status' => AssetStatus::InMaintenance]);

            return $record;
        });
    }

    public function close(
        MaintenanceRecord $record,
        int|float|string|null $cost = null,
        ?string $currency = null,
        ?string $resolution = null,
        ?CarbonInterface $closedAt = null,
    ): MaintenanceRecord {
        return DB::transaction(function () use ($record, $cost, $currency, $resolution, $closedAt) {
            $asset = Asset::query()->lockForUpdate()->findOrFail($record->asset_id);
            $record = MaintenanceRecord::query()->lockForUpdate()->findOrFail($record->id);

            if (! $record->isOpen()) {
                InventoryException::fail('inventory::assets.maintenance_closed');
            }

            $closedAt ??= now();

            if ($closedAt->lt($record->opened_at)) {
                InventoryException::fail('inventory::assets.closed_before_opened');
            }

            $record->update([
                'closed_at' => $closedAt,
                'cost' => $cost,
                'currency' => $cost === null ? null : $currency,
                'resolution' => $resolution,
                'closed_by' => Inventory::actorId(),
            ]);

            if ($asset->status === AssetStatus::InMaintenance) {
                $asset->update(['status' => AssetStatus::InService]);
            }

            if ($record->schedule !== null) {
                $record->schedule->update([
                    'next_due_on' => CarbonImmutable::parse($closedAt)->startOfDay()->addMonthsNoOverflow($record->schedule->interval_months),
                    'alerted_for' => null,
                ]);
            }

            return $record;
        });
    }

    /**
     * Active schedules of assets still owned, due within `$days` of today
     * (or overdue).
     *
     * @return Builder<MaintenanceSchedule>
     */
    public function due(int $days): Builder
    {
        return MaintenanceSchedule::query()
            ->where('is_active', true)
            ->where('next_due_on', '<=', now()->addDays($days)->toDateString())
            ->whereHas('asset', fn (Builder $asset) => $asset->where('status', '!=', AssetStatus::Disposed))
            ->orderBy('next_due_on');
    }
}
