<?php

namespace Add\Inventory\Support\Http;

use Add\Inventory\Catalog\Models\Category;
use Add\Inventory\Catalog\Models\Item;
use Add\Inventory\Inventory;
use Add\Inventory\Support\Place;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The filters lists about assets share: a place as `place[type]` +
 * `place[id]` (anywhere inside it — a branch takes everything it holds; a
 * place that holds nothing, or is unknown, lists nothing), a category with
 * every category below it, and a search on an asset's code or serial.
 */
final class PlaceFilter
{
    public static function from(Request $request, string $field = 'place'): ?Place
    {
        $value = $request->input($field);

        return is_array($value) && is_string($value['type'] ?? null) && is_numeric($value['id'] ?? null) ? Place::from($value) : null;
    }

    /**
     * @param  Builder<\Add\Inventory\Assets\Models\Asset>  $assets
     */
    public static function assetsWithin(Builder $assets, Place $place): Builder
    {
        return $assets->at(Inventory::assetPlaces()->within($place));
    }

    /**
     * @param  Builder<\Add\Inventory\Assets\Models\Asset>  $assets
     */
    public static function assetsInCategory(Builder $assets, int $categoryId): Builder
    {
        $category = Category::query()->find($categoryId);

        return $assets->whereIn('item_id', Item::query()->select('id')->whereIn('category_id', $category?->subtreeIds() ?? []));
    }

    /**
     * @param  Builder<\Add\Inventory\Assets\Models\Asset>  $assets
     */
    public static function assetsMatching(Builder $assets, string $needle): Builder
    {
        $like = '%'.$needle.'%';

        return $assets->where(fn (Builder $q) => $q->where('code', 'like', $like)->orWhere('serial_number', 'like', $like));
    }

    /**
     * What a list of things about assets (custody, maintenance) narrows to
     * through its `asset` relation: `place`, `category_id`.
     */
    public static function throughAsset(Builder $query, Request $request): Builder
    {
        $place = self::from($request);

        return $query
            ->when($place, fn (Builder $q, Place $place) => $q->whereHas('asset', fn (Builder $asset) => self::assetsWithin($asset, $place)))
            ->when($request->filled('category_id'), fn (Builder $q) => $q->whereHas('asset', fn (Builder $asset) => self::assetsInCategory($asset, $request->integer('category_id'))));
    }
}
