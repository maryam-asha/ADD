<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Finance\Models\Currency;
use App\Http\Resources\CurrencyResource;
use Illuminate\Database\Eloquent\Builder;

class CurrencyController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return Currency::class;
    }

    protected function resourceClass(): string
    {
        return CurrencyResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('order');
    }
}
