<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\DedicatedDeskService;
use App\Http\Requests\Admin\StoreSeatDeskRequest;
use App\Http\Requests\Admin\UpdateSeatDeskRequest;
use App\Http\Resources\SeatDeskResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A co_space's desks. Adding, moving or removing one recounts its space's
 * capacity (SeatDesk). Every desk comes back with the Dedicated Desk
 * subscription holding it now, if any. `?free=1` leaves only desks nobody
 * holds; `?free_for_plan=` and `?free_for_membership=` leave the desks free
 * during that plan's or subscription's hours and dates — what the dashboard
 * offers when tying a desk
 * (docs/decisions/coworking-seat-pool.md).
 */
class SeatDeskController extends AdminResourceController
{
    public function __construct(private readonly DedicatedDeskService $desks) {}

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
        $query->select('seats_desks.*')
            ->when(
                $request->filled('space_id'),
                fn (Builder $q) => $q->where('space_id', $request->query('space_id'))
            )
            ->when(
                $request->filled('branch_id'),
                fn (Builder $q) => $q->whereHas('space.building', fn (Builder $b) => $b->where('branch_id', $request->query('branch_id')))
            )
            ->when($request->boolean('free'), fn (Builder $q) => $this->desks->whereFree($q))
            ->when($request->filled('free_for_plan'), fn (Builder $q) => $q->whereKeyNot($this->takenForPlan($request->integer('free_for_plan'))))
            ->when($request->filled('free_for_membership'), fn (Builder $q) => $q->whereKeyNot($this->takenForMembership($request->integer('free_for_membership'))))
            ->orderBy('seats_desks.id');

        $this->desks->withHolder($query);
    }

    /**
     * Desks another subscription holds at some moment a subscription to this
     * plan, sold now, would — what the activation form leaves out.
     *
     * @return list<int>
     */
    private function takenForPlan(int $planId): array
    {
        $plan = Plan::findOrFail($planId);

        return $this->desks->takenDeskIds($plan, now(), now()->addDays($plan->duration_days));
    }

    /**
     * Desks another subscription holds at some moment this one does — what
     * tying its desks leaves out; its own count as free for it.
     *
     * @return list<int>
     */
    private function takenForMembership(int $membershipId): array
    {
        $membership = Membership::with('plan')->findOrFail($membershipId);

        return $this->desks->takenDeskIds(
            $membership->plan,
            $membership->current_period_start,
            $membership->current_period_end,
            $membership->id,
        );
    }

    public function show(int $id): SeatDeskResource
    {
        return new SeatDeskResource($this->desks->withHolder(SeatDesk::query()->select('seats_desks.*'))->findOrFail($id));
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

    /** A desk a live Dedicated Desk subscription holds is untied first, never deleted from under it. */
    public function destroy(int $id): Response
    {
        $desk = $this->desks->withHolder(SeatDesk::query()->select('seats_desks.*'))->findOrFail($id);

        abort_if($desk->membership_id !== null, 422, __('api.admin.seat_desk_held'));

        $desk->delete();

        return response()->noContent();
    }
}
