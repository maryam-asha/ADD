<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\SeatDesk;
use App\Http\Requests\Admin\StoreSeatDeskRequest;
use App\Http\Requests\Admin\UpdateSeatDeskRequest;
use App\Http\Resources\SeatDeskResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeatDeskController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return SeatDesk::class;
    }

    protected function resourceClass(): string
    {
        return SeatDeskResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query->when(
            $request->filled('space_id'),
            fn (Builder $q) => $q->where('space_id', $request->query('space_id'))
        );
    }

    public function store(StoreSeatDeskRequest $request): SeatDeskResource
    {
        return new SeatDeskResource(SeatDesk::create($request->validated()));
    }

    public function update(UpdateSeatDeskRequest $request, SeatDesk $seatDesk): JsonResponse
    {
        $seatDesk->update($request->validated());

        return response()->json(['message' => __('api.admin.seat_desk_updated')]);
    }
}
