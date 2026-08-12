<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\Device;
use App\Http\Requests\Admin\StoreDeviceRequest;
use App\Http\Requests\Admin\UpdateDeviceRequest;
use App\Http\Resources\DeviceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Device::class;
    }

    protected function resourceClass(): string
    {
        return DeviceResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query
            ->when(
                $request->filled('branch_id'),
                fn (Builder $q) => $q->where('branch_id', $request->query('branch_id'))
            )
            ->when(
                $request->filled('space_id'),
                fn (Builder $q) => $q->where('space_id', $request->query('space_id'))
            );
    }

    public function store(StoreDeviceRequest $request): DeviceResource
    {
        return new DeviceResource(Device::create(array_merge(
            ['status' => 'offline'],
            $request->validated()
        )));
    }

    public function update(UpdateDeviceRequest $request, Device $device): JsonResponse
    {
        $device->update($request->validated());

        return response()->json(['message' => __('api.admin.device_updated')]);
    }
}
