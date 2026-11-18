<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Models\ContactLink;
use App\Http\Resources\ContactLinkResource;
use Illuminate\Database\Eloquent\Builder;

class ContactLinkController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return ContactLink::class;
    }

    protected function resourceClass(): string
    {
        return ContactLinkResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query->where('is_visible', true)->orderBy('sort_order');
    }
}
