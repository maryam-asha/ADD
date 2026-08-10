<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Membership\Models\Plan;
use App\Http\Resources\PlanResource;
use Illuminate\Database\Eloquent\Builder;

class PlanController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return Plan::class;
    }

    protected function resourceClass(): string
    {
        return PlanResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('order');
    }
}
