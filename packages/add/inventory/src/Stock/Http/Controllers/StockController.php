<?php

namespace Add\Inventory\Stock\Http\Controllers;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Enums\StockMovementType;
use Add\Inventory\Stock\Http\Requests\StockOperationRequest;
use Add\Inventory\Stock\Http\Resources\StockBalanceResource;
use Add\Inventory\Stock\Http\Resources\StockMovementResource;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\StockOperations;
use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

class StockController extends Controller
{
    /**
     * What is on hand. Filters: `item_id`, `category_id` (with every
     * category below it), `warehouse_id`, or `place[type]` + `place[id]`
     * (the warehouses sitting anywhere inside it); empty balances are left
     * out unless `include_empty=1`.
     */
    public function balances(Request $request): AnonymousResourceCollection
    {
        $balances = StockBalance::query()
            ->with('item.unit')
            ->where('location_type', Warehouse::TYPE)
            ->when($request->filled('item_id'), fn (Builder $query) => $query->where('item_id', $request->integer('item_id')))
            ->when($request->filled('category_id'), function (Builder $query) use ($request) {
                $category = Category::query()->find($request->integer('category_id'));
                $query->whereIn('item_id', Item::query()->select('id')->whereIn('category_id', $category?->subtreeIds() ?? []));
            })
            ->when(Warehouse::idsFromFilter($request->all()), fn (Builder $query, array $ids) => $query->whereIn('location_id', $ids), fn (Builder $query) => $this->unlessFilteredToNone($query, $request))
            ->when(! $request->boolean('include_empty'), fn (Builder $query) => $query->where('quantity', '>', 0))
            ->orderBy('item_id')
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return StockBalanceResource::collection($balances);
    }

    /**
     * The ledger, newest first. Filters: `item_id`, `warehouse_id` or
     * `place`, `type`, `actor_id`, `operation_id`, `source[type]` +
     * `source[id]`, `from` / `to` (days).
     */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $source = $request->input('source');

        $movements = StockMovement::query()
            ->with(['item', 'enteredUnit'])
            ->where('location_type', Warehouse::TYPE)
            ->when($request->filled('item_id'), fn (Builder $query) => $query->where('item_id', $request->integer('item_id')))
            ->when(Warehouse::idsFromFilter($request->all()), fn (Builder $query, array $ids) => $query->whereIn('location_id', $ids), fn (Builder $query) => $this->unlessFilteredToNone($query, $request))
            ->when(StockMovementType::tryFrom((string) $request->input('type')), fn (Builder $query, StockMovementType $type) => $query->where('type', $type))
            ->when($request->filled('actor_id'), fn (Builder $query) => $query->where('actor_id', $request->integer('actor_id')))
            ->when($request->filled('operation_id'), fn (Builder $query) => $query->where('operation_id', (string) $request->input('operation_id')))
            ->when(
                is_array($source) && is_string($source['type'] ?? null),
                fn (Builder $query) => $query->where('source_type', $source['type'])
                    ->when(is_numeric($source['id'] ?? null), fn (Builder $q) => $q->where('source_id', (int) $source['id'])),
            )
            ->when($request->filled('from'), fn (Builder $query) => $query->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return StockMovementResource::collection($movements);
    }

    public function receive(StockOperationRequest $request, StockOperations $operations): JsonResponse
    {
        $data = $request->validated();

        return $this->created($operations->receive($this->warehouse($data['warehouse_id']), $data['lines'], $data['note'] ?? null));
    }

    public function issue(StockOperationRequest $request, StockOperations $operations): JsonResponse
    {
        $data = $request->validated();

        return $this->created($operations->issue(
            $this->warehouse($data['warehouse_id']),
            $data['lines'],
            IssueReason::from($data['reason']),
            $data['note'] ?? null,
        ));
    }

    public function transfer(StockOperationRequest $request, StockOperations $operations): JsonResponse
    {
        $data = $request->validated();

        return $this->created($operations->transfer(
            $this->warehouse($data['from_warehouse_id']),
            $this->warehouse($data['to_warehouse_id']),
            $data['lines'],
            $data['note'] ?? null,
        ));
    }

    /**
     * A count where every line matched the book records nothing.
     */
    public function adjust(StockOperationRequest $request, StockOperations $operations): JsonResponse
    {
        $data = $request->validated();
        $movements = $operations->adjust($this->warehouse($data['warehouse_id']), $data['lines'], $data['note'] ?? null);

        return $movements->isEmpty()
            ? response()->json(['message' => __('inventory::stock.count_matched')])
            : $this->created($movements);
    }

    private function warehouse(int|string $id): Warehouse
    {
        return Warehouse::query()->findOrFail($id);
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     */
    private function created(Collection $movements): JsonResponse
    {
        return StockMovementResource::collection($movements->each->load(['item', 'enteredUnit']))->response()->setStatusCode(201);
    }

    /**
     * A place filter that matched no warehouse lists nothing, rather than
     * everything.
     */
    private function unlessFilteredToNone(Builder $query, Request $request): Builder
    {
        return Warehouse::idsFromFilter($request->all()) === [] ? $query->whereRaw('1 = 0') : $query;
    }
}
