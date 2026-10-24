<?php

namespace Add\Inventory\Assets\Http\Controllers;

use Add\Inventory\Assets\Http\Requests\MaintenanceRequest;
use Add\Inventory\Assets\Http\Resources\MaintenanceScheduleResource;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\MaintenanceSchedule;
use Add\Inventory\Assets\Services\MaintenanceService;
use Add\Inventory\Support\InventoryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class MaintenanceScheduleController extends Controller
{
    public function index(Asset $asset): AnonymousResourceCollection
    {
        return MaintenanceScheduleResource::collection($asset->maintenanceSchedules()->orderBy('next_due_on')->get());
    }

    /**
     * Everything coming due within `days` (default: the configured lead
     * time), overdue first.
     */
    public function due(Request $request, MaintenanceService $maintenance): AnonymousResourceCollection
    {
        $days = min(365, max(0, $request->integer('days', (int) config('inventory.assets.maintenance_lead_days'))));

        return MaintenanceScheduleResource::collection($maintenance->due($days)->with('asset')->get());
    }

    public function store(MaintenanceRequest $request, Asset $asset): MaintenanceScheduleResource
    {
        if ($asset->isDisposed()) {
            InventoryException::fail('inventory::assets.disposed');
        }

        return new MaintenanceScheduleResource($asset->maintenanceSchedules()->create($request->validated()));
    }

    public function update(MaintenanceRequest $request, MaintenanceSchedule $schedule): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['next_due_on'])) {
            $data['alerted_for'] = null;
        }

        $schedule->update($data);

        return response()->json(['message' => __('inventory::assets.schedule_updated')]);
    }

    public function destroy(MaintenanceSchedule $schedule): Response
    {
        $schedule->delete();

        return response()->noContent();
    }
}
