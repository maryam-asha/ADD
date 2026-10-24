<?php

namespace Add\Inventory\Stock\Http\Controllers;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Stock\Enums\IssueReason;
use Add\Inventory\Stock\Enums\StockMovementType;
use Add\Inventory\Stock\Http\Requests\StockRequest;
use Add\Inventory\Stock\Http\Resources\StockBalanceResource;
use Add\Inventory\Stock\Http\Resources\StockMovementResource;
use Add\Inventory\Stock\Models\StockBalance;
use Add\Inventory\Stock\Models\StockMovement;
use Add\Inventory\Stock\Services\StockService;
use Add\Inventory\Support\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class StockController extends Controller
{
    /**
     * What is on hand. Filters: `item_id`, `category_id` (with every
     * category below it), `location[type]` + `location[id]` (with every
     * place inside it unless `within=0`); empty balances are left out
     * unless `include_empty=1`.
     */
    public function balances(Request $request): AnonymousResourceCollection
    {
        $balances = StockBalance::query()
            ->with('item.unit')
            ->when($request->filled('item_id'), fn (Builder $query) => $query->where('item_id', $request->integer('item_id')))
            ->when($request->filled('category_id'), function (Builder $query) use ($request) {
                $category = Category::query()->find($request->integer('category_id'));
                $query->whereIn('item_id', Item::query()->select('id')->whereIn('category_id', $category?->subtreeIds() ?? []));
            })
            ->when($this->place($request), fn (Builder $query, Place $place) => $query->at(
                $request->boolean('within', true) ? Inventory::locations()->within($place) : [$place],
            ))
            ->when(! $request->boolean('include_empty'), fn (Builder $query) => $query->where('quantity', '>', 0))
            ->orderBy('item_id')
            ->orderBy('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return StockBalanceResource::collection($balances);
    }

    /**
     * The ledger, newest first. Filters: `item_id`, `location` (+ `within`),
     * `type`, `source[type]` + `source[id]`, `from` / `to` (days).
     */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $source = $request->input('source');

        $movements = StockMovement::query()
            ->with('item')
            ->when($request->filled('item_id'), fn (Builder $query) => $query->where('item_id', $request->integer('item_id')))
            ->when($this->place($request), fn (Builder $query, Place $place) => $query->where(function (Builder $query) use ($request, $place) {
                $places = $request->boolean('within', true) ? Inventory::locations()->within($place) : [$place];

                foreach (collect($places)->groupBy('type') as $type => $group) {
                    $query->orWhere(fn (Builder $q) => $q->where('location_type', $type)->whereIn('location_id', $group->pluck('id')));
                }
            }))
            ->when(StockMovementType::tryFrom((string) $request->input('type')), fn (Builder $query, StockMovementType $type) => $query->where('type', $type))
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

    public function receive(StockRequest $request, StockService $stock): StockMovementResource
    {
        $data = $request->validated();

        return new StockMovementResource($stock->receive(
            Item::query()->findOrFail($data['item_id']),
            Place::from($data['location']),
            $data['quantity'],
            $data['unit_cost'] ?? null,
            note: $data['note'] ?? null,
        ));
    }

    public function issue(StockRequest $request, StockService $stock): StockMovementResource
    {
        $data = $request->validated();

        return new StockMovementResource($stock->issue(
            Item::query()->findOrFail($data['item_id']),
            Place::from($data['location']),
            $data['quantity'],
            IssueReason::from($data['reason']),
            note: $data['note'] ?? null,
        ));
    }

    public function transfer(StockRequest $request, StockService $stock): JsonResponse
    {
        $data = $request->validated();

        return StockMovementResource::collection(collect($stock->transfer(
            Item::query()->findOrFail($data['item_id']),
            Place::from($data['from']),
            Place::from($data['to']),
            $data['quantity'],
            $data['note'] ?? null,
        )))->response()->setStatusCode(201);
    }

    /**
     * A count that matched the book records nothing.
     */
    public function adjust(StockRequest $request, StockService $stock): JsonResponse|StockMovementResource
    {
        $data = $request->validated();
        $movement = $stock->adjust(
            Item::query()->findOrFail($data['item_id']),
            Place::from($data['location']),
            $data['counted'],
            $data['unit_cost'] ?? null,
            $data['note'] ?? null,
        );

        return $movement === null
            ? response()->json(['message' => __('inventory::stock.count_matched')])
            : new StockMovementResource($movement);
    }

    private function place(Request $request): ?Place
    {
        $value = $request->input('location');

        return is_array($value) && is_string($value['type'] ?? null) && is_numeric($value['id'] ?? null) ? Place::from($value) : null;
    }
}
