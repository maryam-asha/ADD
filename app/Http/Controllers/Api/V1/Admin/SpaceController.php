<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Services\DedicatedDeskService;
use App\Http\Requests\Admin\StoreSpaceRequest;
use App\Http\Requests\Admin\UpdateSpaceAmenitiesRequest;
use App\Http\Requests\Admin\UpdateSpaceRequest;
use App\Http\Requests\Admin\UpdateSpaceStatusRequest;
use App\Http\Resources\SpaceResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        $space = DB::transaction(function () use ($request) {
            $this->lockBranchForCoSpace($request->validated());

            $space = Space::create(array_merge(
                ['status' => OperationalStatus::Active],
                $request->validated()
            ));
            // A co_space's capacity is its desks, never a typed number
            // (docs/decisions/coworking-seat-pool.md).
            SeatDesk::recountCapacity($space->id);

            return $space;
        });

        return new SpaceResource($space->refresh());
    }

    public function update(UpdateSpaceRequest $request, Space $space): JsonResponse
    {
        DB::transaction(function () use ($request, $space) {
            $this->lockBranchForCoSpace($request->validated(), $space->id);

            $space->update($request->validated());
            SeatDesk::recountCapacity($space->id);
        });

        return response()->json(['message' => __('api.admin.space_updated')]);
    }

    /**
     * Deleting a co_space takes its desks with it, so one whose desks live
     * Dedicated Desk subscriptions hold is refused until they are untied.
     */
    public function destroy(int $id): Response
    {
        abort_if(app(DedicatedDeskService::class)->spaceHasHeldDesks($id), 422, __('api.admin.co_space_desks_held'));

        return parent::destroy($id);
    }

    /**
     * StoreSpaceRequest already refused a second co_space in the branch; this
     * re-checks it on the locked branch row, so two requests at once cannot
     * both pass (docs/decisions/coworking-seat-pool.md).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function lockBranchForCoSpace(array $attributes, ?int $exceptId = null): void
    {
        if (($attributes['space_type'] ?? null) !== SpaceType::CoSpace->value) {
            return;
        }

        $branchId = Building::query()->whereKey($attributes['building_id'])->value('branch_id');
        Branch::query()->whereKey($branchId)->lockForUpdate()->first();

        if (Space::branchHasCoSpace((int) $branchId, $exceptId)) {
            throw ValidationException::withMessages(['space_type' => __('api.admin.co_space_exists')]);
        }
    }

    /**
     * Amenities are a separate action from the general edit form — a space's
     * `amenities` are set here, never through store()/update() above, the
     * same way `status` is its own action below rather than a field on the
     * main payload.
     */
    public function updateAmenities(UpdateSpaceAmenitiesRequest $request, Space $space): JsonResponse
    {
        $space->amenities()->delete();

        foreach ($request->validated('amenities') as $amenity) {
            $space->amenities()->create(['amenity' => $amenity]);
        }

        return response()->json(['message' => __('api.admin.space_amenities_updated')]);
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
