<?php

namespace Add\Inventory\Assets\Http\Controllers;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Assets\Http\Requests\AssetActionRequest;
use Add\Inventory\Assets\Http\Requests\RegisterAssetBatchRequest;
use Add\Inventory\Assets\Http\Requests\RegisterAssetRequest;
use Add\Inventory\Assets\Http\Requests\UpdateAssetRequest;
use Add\Inventory\Assets\Http\Resources\AssetAssignmentResource;
use Add\Inventory\Assets\Http\Resources\AssetMovementResource;
use Add\Inventory\Assets\Http\Resources\AssetResource;
use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Assets\Services\AssetService;
use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Support\Http\PlaceFilter;
use Add\Inventory\Support\Place;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;

class AssetController extends Controller
{
    private const LIST_RELATIONS = ['item.category', 'currentAssignment', 'additions'];

    /**
     * Filters: `item_id`, `category_id` (with every category below it),
     * `status`, `location[type]` + `location[id]` (with every place inside
     * it unless `within=0`), `custodian[type]` + `custodian[id]` (held now),
     * `search` (code or serial number).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $assets = Asset::query()
            ->with(self::LIST_RELATIONS)
            ->when($request->filled('item_id'), fn (Builder $query) => $query->where('item_id', $request->integer('item_id')))
            ->when($request->filled('category_id'), function (Builder $query) use ($request) {
                $category = Category::query()->find($request->integer('category_id'));
                $query->whereIn('item_id', Item::query()->select('id')->whereIn('category_id', $category?->subtreeIds() ?? []));
            })
            ->when(AssetStatus::tryFrom((string) $request->input('status')), fn (Builder $query, AssetStatus $status) => $query->where('status', $status))
            ->when(PlaceFilter::from($request, 'location'), fn (Builder $query, Place $place) => $query->at(
                $request->boolean('within', true) ? Inventory::assetPlaces()->within($place) : [$place],
            ))
            ->when(PlaceFilter::from($request, 'custodian'), fn (Builder $query, Place $custodian) => $query->whereHas(
                'currentAssignment',
                fn (Builder $assignment) => $assignment->where('custodian_type', $custodian->type)->where('custodian_id', $custodian->id),
            ))
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $needle = '%'.$request->string('search').'%';
                $query->where(fn (Builder $q) => $q->where('code', 'like', $needle)->orWhere('serial_number', 'like', $needle));
            })
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return AssetResource::collection($assets);
    }

    public function store(RegisterAssetRequest $request, AssetService $service): JsonResponse
    {
        $data = $request->validated();
        $assets = $service->register(
            Item::query()->findOrFail($data['item_id']),
            isset($data['location']) ? Place::from($data['location']) : null,
            Arr::except($data, ['item_id', 'location', 'count', 'draft', 'serial_numbers']),
            $data['count'] ?? 1,
            $request->wantsDraft(),
            $data['serial_numbers'] ?? [],
        );

        return AssetResource::collection($assets->each->load(self::LIST_RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * Several asset items registered in one place at once (a room's
     * contents): the place and the purchase's shared fields once, a line
     * per item.
     */
    public function storeBatch(RegisterAssetBatchRequest $request, AssetService $service): JsonResponse
    {
        $data = $request->validated();
        $items = Item::query()->findMany(array_column($data['lines'], 'item_id'))->keyBy('id');

        $assets = $service->registerBatch(
            isset($data['location']) ? Place::from($data['location']) : null,
            array_map(fn (array $line) => [
                'item' => $items->get($line['item_id']),
                'count' => $line['count'] ?? 1,
                'details' => Arr::except($line, ['item_id', 'count', 'serial_numbers']),
                'serials' => $line['serial_numbers'] ?? [],
            ], $data['lines']),
            Arr::only($data, ['purchased_on', 'in_service_on', 'supplier', 'reference', 'attachments', 'currency', 'notes']),
            $request->wantsDraft(),
        );

        return AssetResource::collection($assets->each->load(self::LIST_RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * A draft approved into service: it takes its code and arrives at its
     * place.
     */
    public function approve(Asset $asset, AssetService $service): JsonResponse
    {
        $service->approve($asset);

        return response()->json(['message' => __('inventory::assets.approved')]);
    }

    /**
     * Only a draft is deleted; a registered asset is disposed of.
     */
    public function destroy(Asset $asset, AssetService $service): JsonResponse
    {
        $service->deleteDraft($asset);

        return response()->json(['message' => __('inventory::assets.draft_deleted')]);
    }

    public function show(Asset $asset): AssetResource
    {
        return new AssetResource($asset->load([
            ...self::LIST_RELATIONS,
            'openMaintenance',
            'movements',
            'assignments',
            'maintenanceRecords',
            'maintenanceSchedules',
        ]));
    }

    /**
     * What a scanned QR label opens.
     */
    public function scan(string $token): AssetResource
    {
        return $this->show(Asset::query()->where('qr_token', $token)->firstOrFail());
    }

    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        $asset->update($request->validated());

        return response()->json(['message' => __('inventory::assets.updated')]);
    }

    public function status(AssetActionRequest $request, Asset $asset, AssetService $service): JsonResponse
    {
        $service->setStatus($asset, AssetStatus::from($request->validated('status')));

        return response()->json(['message' => __('inventory::assets.status_updated')]);
    }

    public function move(AssetActionRequest $request, Asset $asset, AssetService $service): AssetMovementResource
    {
        return new AssetMovementResource($service->move(
            $asset,
            Place::from($request->validated('location')),
            $request->validated('note'),
            $this->moment($request->validated('moved_at')),
        ));
    }

    public function assign(AssetActionRequest $request, Asset $asset, AssetService $service): AssetAssignmentResource
    {
        return new AssetAssignmentResource($service->assign(
            $asset,
            Place::from($request->validated('custodian')),
            $request->validated('note'),
            $this->moment($request->validated('assigned_at')),
        ));
    }

    public function takeBack(AssetActionRequest $request, Asset $asset, AssetService $service): JsonResponse
    {
        $service->takeBack($asset, $request->validated('note'), $this->moment($request->validated('returned_at')));

        return response()->json(['message' => __('inventory::assets.returned')]);
    }

    public function dispose(AssetActionRequest $request, Asset $asset, AssetService $service): JsonResponse
    {
        $service->dispose(
            $asset,
            DisposalMethod::from($request->validated('method')),
            CarbonImmutable::parse($request->validated('disposed_on')),
            $request->validated('proceeds'),
            $request->validated('note'),
        );

        return response()->json(['message' => __('inventory::assets.disposed_done')]);
    }

    private function moment(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value);
    }
}
