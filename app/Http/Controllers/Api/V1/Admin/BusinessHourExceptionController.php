<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\BusinessHourException;
use App\Http\Requests\Admin\StoreBusinessHourExceptionRequest;
use App\Http\Requests\Admin\UpdateBusinessHourExceptionRequest;
use App\Http\Resources\BusinessHourExceptionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessHourExceptionController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return BusinessHourException::class;
    }

    protected function resourceClass(): string
    {
        return BusinessHourExceptionResource::class;
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

    public function store(StoreBusinessHourExceptionRequest $request): BusinessHourExceptionResource
    {
        return new BusinessHourExceptionResource(BusinessHourException::create($request->validated()));
    }

    public function update(UpdateBusinessHourExceptionRequest $request, BusinessHourException $businessHourException): JsonResponse
    {
        $businessHourException->update($request->validated());

        return response()->json(['message' => __('api.admin.business_hour_exception_updated')]);
    }
}
