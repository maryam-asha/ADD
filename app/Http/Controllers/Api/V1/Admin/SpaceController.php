<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\Space;
use App\Http\Requests\Admin\StoreSpaceRequest;
use App\Http\Requests\Admin\UpdateSpaceRequest;
use App\Http\Requests\Admin\UpdateSpaceStatusRequest;
use App\Http\Resources\SpaceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpaceController extends AdminResourceController
{
    use LogsSensitiveActions;

    protected function modelClass(): string
    {
        return Space::class;
    }

    protected function resourceClass(): string
    {
        return SpaceResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    protected function applyIndexFilters(Builder $query, Request $request): void
    {
        $query
            ->when(
                $request->filled('building_id'),
                fn (Builder $q) => $q->where('building_id', $request->query('building_id'))
            )
            ->when(
                $request->filled('zone_id'),
                fn (Builder $q) => $q->where('zone_id', $request->query('zone_id'))
            );
    }

    public function store(StoreSpaceRequest $request): SpaceResource
    {
        return new SpaceResource(Space::create(array_merge(
            ['status' => OperationalStatus::Active],
            $request->validated()
        )));
    }

    public function update(UpdateSpaceRequest $request, Space $space): JsonResponse
    {
        $space->update($request->validated());

        return response()->json(['message' => __('api.admin.space_updated')]);
    }

    public function updateStatus(UpdateSpaceStatusRequest $request, Space $space): JsonResponse
    {
        $before = $space->status;

        $space->update($request->validated());

        $this->logSensitiveAction('space_status_changed', $space, [
            'before' => $before,
            'after' => $space->status,
        ]);

        return response()->json(['message' => __('api.admin.space_status_updated')]);
    }
}
