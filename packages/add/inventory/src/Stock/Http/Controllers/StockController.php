<?php

namespace Add\Inventory\Stock\Http\Controllers;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Enums\OperationKind;
use Add\Inventory\Stock\Enums\StockMovementType;
use Add\Inventory\Stock\Http\Requests\StockOperationRequest;
use Add\Inventory\Stock\Http\Resources\StockBalanceResource;
use Add\Inventory\Stock\Http\Resources\StockMovementResource;
use Add\Inventory\Stock\Http\Resources\StockOperationResource;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Models\StockOperation;
use Add\Inventory\Stock\Services\StockOperations;
use Add\Inventory\Warehouses\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class StockController extends Controller
{
    /**
     * What is on hand. Filters: `item_id`, `search` (item name, code or
     * barcode), `category_id` (with every
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
            ->when($request->filled('search'), function (Builder $query) use ($request) {
                $needle = '%'.$request->string('search').'%';
                $query->whereIn('item_id', Item::query()->select('id')->where(
                    fn (Builder $q) => $q->where('sku', 'like', $needle)->orWhere('barcode', 'like', $needle)->orWhere('name', 'like', $needle),
                ));
            })
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

    /**
     * Operations as staff recorded them, the latest day first. Filters:
     * `warehouse_id` (from or to), `kind`, `actor_id`, `from` / `to` (on
     * the operation's date).
     */
    public function operations(Request $request): AnonymousResourceCollection
    {
        $operations = StockOperation::query()
            // A transfer's two halves are one line of the operation.
            ->withCount(['movements as lines_count' => fn (Builder $query) => $query->where('type', '!=', StockMovementType::TransferIn)])
            ->when($request->filled('warehouse_id'), fn (Builder $query) => $query->where(
                fn (Builder $q) => $q->where('warehouse_id', $request->integer('warehouse_id'))->orWhere('to_warehouse_id', $request->integer('warehouse_id')),
            ))
            ->when(OperationKind::tryFrom((string) $request->input('kind')), fn (Builder $query, OperationKind $kind) => $query->where('kind', $kind))
            ->when($request->filled('actor_id'), fn (Builder $query) => $query->where('actor_id', $request->integer('actor_id')))
            ->when($request->filled('from'), fn (Builder $query) => $query->where('operation_date', '>=', $request->date('from')->toDateString()))
            ->when($request->filled('to'), fn (Builder $query) => $query->where('operation_date', '<=', $request->date('to')->toDateString()))
            ->orderByDesc('operation_date')
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return StockOperationResource::collection($operations);
    }

    public function operation(StockOperation $operation): StockOperationResource
    {
        return new StockOperationResource($operation->load(['movements.item', 'movements.enteredUnit']));
    }

    public function receive(StockOperationRequest $request, StockOperations $operations): JsonResponse
    {
        return $this->recorded($operations->record(OperationKind::Receive, $request->validated()));
    }

    public function issue(StockOperationRequest $request, StockOperations $operations): JsonResponse
    {
        $data = $request->validated();

        return $this->recorded($operations->record(OperationKind::forIssue(IssueReason::from($data['reason'])), $data));
    }

    public function transfer(StockOperationRequest $request, StockOperations $operations): JsonResponse
    {
        return $this->recorded($operations->record(OperationKind::Transfer, $request->validated()));
    }

    /**
     * A count where every line matched the book records nothing.
     */
    public function adjust(StockOperationRequest $request, StockOperations $operations): JsonResponse
    {
        return $this->recorded($operations->record(OperationKind::Adjust, $request->validated()));
    }

    private function recorded(?StockOperation $operation): JsonResponse
    {
        return $operation === null
            ? response()->json(['message' => __('inventory::stock.count_matched')])
            : $this->operation($operation)->response()->setStatusCode(201);
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
