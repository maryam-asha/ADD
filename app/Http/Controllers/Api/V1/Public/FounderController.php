<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Models\Founder;
use App\Http\Resources\FounderResource;
use Illuminate\Database\Eloquent\Builder;

class FounderController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return Founder::class;
    }

    protected function resourceClass(): string
    {
        return FounderResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->orderBy('order');
    }
}
