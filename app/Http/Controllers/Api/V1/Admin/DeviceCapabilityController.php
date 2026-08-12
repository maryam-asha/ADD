<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\DeviceCapability;
use App\Http\Requests\Admin\StoreDeviceCapabilityRequest;
use App\Http\Requests\Admin\UpdateDeviceCapabilityRequest;
use App\Http\Resources\DeviceCapabilityResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceCapabilityController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return DeviceCapability::class;
    }

    protected function resourceClass(): string
    {
        return DeviceCapabilityResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('device_id'),
            fn (Builder $q) => $q->where('device_id', $request->query('device_id'))
        );
    }

    public function store(StoreDeviceCapabilityRequest $request): DeviceCapabilityResource
    {
        return new DeviceCapabilityResource(DeviceCapability::create($request->validated()));
    }

    public function update(UpdateDeviceCapabilityRequest $request, DeviceCapability $deviceCapability): JsonResponse
    {
        $deviceCapability->update($request->validated());

        return response()->json(['message' => __('api.admin.device_capability_updated')]);
    }
}
