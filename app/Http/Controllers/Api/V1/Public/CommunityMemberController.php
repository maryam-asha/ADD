<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Models\CommunityMember;
use App\Http\Resources\CommunityMemberResource;
use Illuminate\Database\Eloquent\Builder;

class CommunityMemberController extends PublicResourceController
{
    protected function modelClass(): string
    {
        return CommunityMember::class;
    }

    protected function resourceClass(): string
    {
        return CommunityMemberResource::class;
    }

    protected function scopeQuery(Builder $query): Builder
    {
        $query->where('published', true)->orderBy('order');

        if ($category = request()->query('category')) {
            $query->where('category', $category);
        }

        return $query;
    }
}
