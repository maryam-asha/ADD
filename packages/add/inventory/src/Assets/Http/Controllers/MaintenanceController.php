<?php

namespace Add\Inventory\Assets\Http\Controllers;

use Add\Inventory\Assets\Enums\MaintenanceType;
use Add\Inventory\Assets\Http\Requests\MaintenanceRequest;
use Add\Inventory\Assets\Http\Resources\MaintenanceRecordResource;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Models\MaintenanceRecord;
use Add\Inventory\Assets\Models\MaintenanceSchedule;
use Add\Inventory\Assets\Services\MaintenanceService;
use Add\Inventory\Support\Http\PlaceFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class MaintenanceController extends Controller
{
    /**
     * Filters: `asset_id`, `open` (only what is still being worked on),
     * `place[type]` + `place[id]` (where the asset sits, anywhere inside),
     * `category_id`, `type`, `from` / `to` (days, on when it was opened),
     * `search` (the asset's code or serial, the description, the vendor).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $records = MaintenanceRecord::query()
            ->with('asset.item')
            ->when($request->filled('asset_id'), fn (Builder $query) => $query->where('asset_id', $request->integer('asset_id')))
            ->when($request->boolean('open'), fn (Builder $query) => $query->whereNull('closed_at'))
            ->tap(fn (Builder $query) => PlaceFilter::throughAsset($query, $request))
            ->when(MaintenanceType::tryFrom((string) $request->input('type')), fn (Builder $query, MaintenanceType $type) => $query->where('type', $type))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('opened_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('opened_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $needle = (string) $request->string('search');
                $query->where(fn (Builder $q) => $q
                    ->where('description', 'like', '%'.$needle.'%')
                    ->orWhere('vendor', 'like', '%'.$needle.'%')
                    ->orWhereHas('asset', fn (Builder $asset) => PlaceFilter::assetsMatching($asset, $needle)));
            })
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return MaintenanceRecordResource::collection($records);
    }

    public function open(MaintenanceRequest $request, Asset $asset, MaintenanceService $maintenance): MaintenanceRecordResource
    {
        $data = $request->validated();

        return new MaintenanceRecordResource($maintenance->open(
            $asset,
            MaintenanceType::from($data['type']),
            $data['description'],
            $data['vendor'] ?? null,
            isset($data['schedule_id']) ? MaintenanceSchedule::query()->findOrFail($data['schedule_id']) : null,
            isset($data['opened_at']) ? CarbonImmutable::parse($data['opened_at']) : null,
        ));
    }

    public function close(MaintenanceRequest $request, MaintenanceRecord $record, MaintenanceService $maintenance): JsonResponse
    {
        $data = $request->validated();
        $maintenance->close(
            $record,
            $data['cost'] ?? null,
            $data['currency'] ?? null,
            $data['resolution'] ?? null,
            isset($data['closed_at']) ? CarbonImmutable::parse($data['closed_at']) : null,
        );

        return response()->json(['message' => __('inventory::assets.maintenance_closed_done')]);
    }
}
