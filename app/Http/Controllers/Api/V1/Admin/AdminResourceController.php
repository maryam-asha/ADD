<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;

/**
 * The part of admin CRUD that's identical for every resource here (list,
 * show, delete) lives once, in one place. Create/update stay on the
 * concrete controllers because that's exactly where they differ — a typed
 * Form Request per resource, not a rules() array trying to be generic.
 */
abstract class AdminResourceController extends Controller
{
    abstract protected function modelClass(): string;

    abstract protected function resourceClass(): string;

    public function index(): AnonymousResourceCollection
    {
        $query = ($this->modelClass())::query();

        if ($this->hasOrderColumn()) {
            $query->orderBy('order');
        }

        return ($this->resourceClass())::collection($query->get());
    }

    public function show(int $id): JsonResource
    {
        $model = ($this->modelClass())::findOrFail($id);

        return new ($this->resourceClass())($model);
    }

    public function destroy(int $id): Response
    {
        ($this->modelClass())::findOrFail($id)->delete();

        return response()->noContent();
    }

    protected function hasOrderColumn(): bool
    {
        return true;
    }
}
