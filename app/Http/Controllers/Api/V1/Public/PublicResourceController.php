<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Public listings only ever differ by which rows are visible (published,
 * active, ...) — that's the one hook (scopeQuery) each concrete controller
 * overrides; everything else about "list this resource" is identical.
 */
abstract class PublicResourceController extends Controller
{
    abstract protected function modelClass(): string;

    abstract protected function resourceClass(): string;

    public function index(): AnonymousResourceCollection
    {
        $query = $this->scopeQuery(($this->modelClass())::query());

        return ($this->resourceClass())::collection($query->get());
    }

    protected function scopeQuery(Builder $query): Builder
    {
        return $query;
    }
}
