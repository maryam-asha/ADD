<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Retail\Models\ItemSale;
use App\Http\Controllers\Controller;
use App\Http\Resources\Retail\ItemSaleResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Reception's sales, newest first. Filters: `user_id`, `from` / `to`
 * (days). Not an AdminResourceController: sales grow without bound, so
 * this paginates.
 */
class ItemSaleController extends Controller
{
    private const RELATIONS = ['user', 'seller', 'lines.item', 'payment'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $sales = ItemSale::query()
            ->with(self::RELATIONS)
            ->when($request->filled('user_id'), fn ($query) => $query->where('user_id', $request->integer('user_id')))
            ->when($request->filled('from'), fn ($query) => $query->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($query) => $query->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->latest('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return ItemSaleResource::collection($sales);
    }

    public function show(ItemSale $itemSale): ItemSaleResource
    {
        return new ItemSaleResource($itemSale->load(self::RELATIONS));
    }
}
