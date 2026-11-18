<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Retail\Models\SaleItem;
use App\Http\Requests\Admin\SaleItemRequest;
use App\Http\Resources\Retail\SaleItemResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What reception may sell, and for how many points. The stock itself is
 * kept in the inventory (inventory/stock/*).
 */
class SaleItemController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return SaleItem::class;
    }

    protected function resourceClass(): string
    {
        return SaleItemResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->with('item.unit')->orderBy('id');

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }
    }

    public function store(SaleItemRequest $request): SaleItemResource
    {
        return new SaleItemResource(SaleItem::query()->create($request->validated())->load('item.unit'));
    }

    public function update(SaleItemRequest $request, SaleItem $saleItem): JsonResponse
    {
        $saleItem->update($request->validated());

        return response()->json(['message' => __('api.item_sale.sale_item_updated')]);
    }
}
