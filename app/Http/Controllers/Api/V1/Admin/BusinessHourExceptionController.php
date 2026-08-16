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
        $data = $request->validated();

        // A partial update that flips is_closed to true without resending
        // open/close times (or vice versa) must not leave the row's OTHER
        // half stale from before — explicitly null the times whenever the
        // validated is_closed is true, rather than trusting the input to
        // have supplied nulls.
        if ($data['is_closed']) {
            $data['open_time'] = null;
            $data['close_time'] = null;
        }

        $businessHourException->update($data);

        return response()->json(['message' => __('api.admin.business_hour_exception_updated')]);
    }
}
