<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Http\Requests\Admin\StorePrivateOfficeRequestRequest;
use App\Http\Requests\Admin\UpdatePrivateOfficeRequestRequest;
use App\Http\Resources\PrivateOfficeRequestResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivateOfficeRequestController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return PrivateOfficeRequest::class;
    }

    protected function resourceClass(): string
    {
        return PrivateOfficeRequestResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    /** Each request names the provider that brought it, and the list filters by one. */
    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->with('provider')
            ->when($request->filled('provider_id'), fn (Builder $q) => $q->where('provider_id', $request->integer('provider_id')));
    }

    public function store(StorePrivateOfficeRequestRequest $request): PrivateOfficeRequestResource
    {
        return new PrivateOfficeRequestResource(PrivateOfficeRequest::create([
            ...$request->validated(),
            'status' => PrivateOfficeRequestStatus::Requested,
        ]));
    }

    public function update(UpdatePrivateOfficeRequestRequest $request, PrivateOfficeRequest $privateOfficeRequest): JsonResponse
    {
        $privateOfficeRequest->update($request->validated());

        return response()->json(['message' => __('api.admin.private_office_request_updated')]);
    }
}
