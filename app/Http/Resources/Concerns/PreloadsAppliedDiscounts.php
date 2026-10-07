<?php

namespace App\Http\Resources\Concerns;

use App\Support\Presenters\AppliedDiscountPresenter;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;

/**
 * A resource showing `discounts`, listed: every record's ledger rows are
 * loaded in one query before the rows render, wherever the list is built.
 */
trait PreloadsAppliedDiscounts
{
    public static function collection($resource)
    {
        $records = $resource instanceof AbstractPaginator || $resource instanceof AbstractCursorPaginator ? $resource->items() : $resource;

        if (is_iterable($records)) {
            app(AppliedDiscountPresenter::class)->preload($records);
        }

        return parent::collection($resource);
    }
}
