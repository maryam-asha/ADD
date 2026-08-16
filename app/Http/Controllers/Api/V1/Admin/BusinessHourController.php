<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\BusinessHour;
use App\Http\Requests\Admin\StoreBusinessHourRequest;
use App\Http\Requests\Admin\UpdateBusinessHourRequest;
use App\Http\Resources\BusinessHourResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessHourController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return BusinessHour::class;
    }

    protected function resourceClass(): string
    {
        return BusinessHourResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('branch_id'),
            fn (Builder $q) => $q->where('branch_id', $request->query('branch_id'))
        );
    }

    public function store(StoreBusinessHourRequest $request): BusinessHourResource
    {
        return new BusinessHourResource(BusinessHour::create($request->validated()));
    }

    public function update(UpdateBusinessHourRequest $request, BusinessHour $businessHour): JsonResponse
    {
        $businessHour->update($request->validated());

        return response()->json(['message' => __('api.admin.business_hour_updated')]);
    }
}
