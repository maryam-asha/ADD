<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Experience\Models\EventCategory;
use App\Http\Resources\EventCategoryResource;
use Illuminate\Database\Eloquent\Builder;

class EventCategoryController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return EventCategory::class;
    }

    protected function resourceClass(): string
    {
        return EventCategoryResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->orderBy('order')
            ->orderBy('id');
    }
}
