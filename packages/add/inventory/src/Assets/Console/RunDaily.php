<?php

namespace Add\Inventory\Assets\Console;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\MaintenanceSchedule;
use Add\Inventory\Assets\Services\MaintenanceService;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Contracts\InventoryNotifier;
use Add\Inventory\Events\DepreciationMonthClosed;
use Add\Inventory\Inventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Announces, once each, the servicing coming due (or overdue) and the
 * warranties about to end, to everyone who looks after maintenance.
 */
class RunDaily extends Command
{
    protected $signature = 'inventory:run-daily';

    protected $description = 'Announce maintenance coming due, warranties ending, and the close of last month\'s depreciation';

    public function handle(MaintenanceService $maintenance, InventoryNotifier $notifier): int
    {
        $due = 0;

        $maintenance->due((int) config('inventory.assets.maintenance_lead_days'))
            ->with('asset')
            ->get()
            ->reject(fn (MaintenanceSchedule $schedule) => $schedule->alerted_for?->equalTo($schedule->next_due_on))
            ->each(function (MaintenanceSchedule $schedule) use ($notifier, &$due) {
                $notifier->notifyHolders(Abilities::ASSETS_MAINTENANCE, 'maintenance_due', [
                    'asset' => (string) $schedule->asset->code,
                    'title' => $schedule->title,
                    'date' => $schedule->next_due_on->toDateString(),
                ], ['target_type' => 'inventory_asset', 'target_id' => (string) $schedule->asset->id]);
                $schedule->update(['alerted_for' => $schedule->next_due_on]);
                $due++;
            });

        $ending = 0;

        Asset::query()
            ->whereNull('warranty_alerted_at')
            ->whereNotIn('status', [AssetStatus::Disposed, AssetStatus::Draft])
            ->whereNotNull('warranty_until')
            ->where('warranty_until', '>=', now()->toDateString())
            ->where('warranty_until', '<=', now()->addDays((int) config('inventory.assets.warranty_lead_days'))->toDateString())
            ->get()
            ->each(function (Asset $asset) use ($notifier, &$ending) {
                $notifier->notifyHolders(Abilities::ASSETS_MAINTENANCE, 'warranty_ending', [
                    'asset' => (string) $asset->code,
                    'date' => $asset->warranty_until->toDateString(),
                ], ['target_type' => 'inventory_asset', 'target_id' => (string) $asset->id]);
                $asset->update(['warranty_alerted_at' => now()]);
                $ending++;
            });

        $this->closeLastMonth();

        $this->info("Maintenance due: {$due}. Warranties ending: {$ending}.");

        return self::SUCCESS;
    }

    /**
     * Last month's depreciation is final: announced once, on the first run
     * of a new month (the month closed is remembered in inv_sequences).
     */
    private function closeLastMonth(): void
    {
        $month = Inventory::today()->startOfMonth()->subMonth();
        $key = (int) $month->format('Ym');

        $first = DB::transaction(function () use ($key) {
            $closed = DB::table('inv_sequences')->where('name', 'depreciation.closed')->lockForUpdate()->value('value');

            if ($closed !== null && (int) $closed >= $key) {
                return false;
            }

            DB::table('inv_sequences')->upsert(['name' => 'depreciation.closed', 'value' => $key], ['name'], ['value']);

            return true;
        });

        if ($first) {
            DepreciationMonthClosed::dispatch($month->year, $month->month);
        }
    }
}
