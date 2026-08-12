<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Http\Requests\Admin\StorePrivateOfficeRequestRequest;
use App\Http\Requests\Admin\UpdatePrivateOfficeRequestRequest;
use App\Http\Resources\PrivateOfficeRequestResource;
use Illuminate\Http\JsonResponse;

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
