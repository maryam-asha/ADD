<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Models\Partner;
use App\Http\Resources\PartnerResource;
use Illuminate\Database\Eloquent\Builder;

class PartnerController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return Partner::class;
    }

    protected function resourceClass(): string
    {
        return PartnerResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('order');
    }
}
