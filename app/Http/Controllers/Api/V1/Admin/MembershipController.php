<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\DeskAssignmentRefusedException;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Models\PlanModule;
use App\Domain\Membership\Services\DedicatedDeskService;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\MembershipResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AssignMembershipDesksRequest;
use App\Http\Requests\Admin\IndexAdminMembershipRequest;
use App\Http\Requests\Admin\StoreAdminMembershipRequest;
use App\Http\Resources\MembershipResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Operations activating a plan for a member or company from the dashboard,
 * with no wallet debit — the arrangement was paid for outside the app
 * (docs/decisions/plan-access-windows.md). This is how a private plan
 * (`is_public = false`) reaches the company staff it was negotiated for,
 * since nobody can browse or buy one.
 *
 * Does not extend AdminResourceController: memberships have no `order`
 * column and no delete semantics, so the shape genuinely differs — the same
 * call UserController and ErrorLogController already make.
 */
class MembershipController extends Controller
{
    /**
     * Subscriptions, newest first and paginated, each with its owner's name
     * and its desks. Filters: `dedicated=1` (Dedicated Desk plans only),
     * `live=1` (active and not yet ended), `without_desk=1` (no desk tied yet
     * — how staff find one bought in the app) and `branch_id`
     * (docs/decisions/coworking-seat-pool.md).
     */
    public function index(IndexAdminMembershipRequest $request): AnonymousResourceCollection
    {
        $memberships = Membership::query()
            ->with(['plan.planModule', 'seatDesks'])
            ->when($request->boolean('dedicated'), fn (Builder $q) => $q->whereHas(
                'plan.planModule',
                fn (Builder $m) => $m->where('slug', PlanModule::DEDICATED_DESK),
            ))
            ->when($request->boolean('live'), fn (Builder $q) => $q
                ->where('status', MembershipStatus::Active->value)
                ->where('current_period_end', '>=', now()))
            ->when($request->boolean('without_desk'), fn (Builder $q) => $q->whereDoesntHave('seatDesks'))
            ->when($request->filled('branch_id'), fn (Builder $q) => $q->where('branch_id', $request->integer('branch_id')))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        $this->nameOwners($memberships->getCollection());

        return MembershipResource::collection($memberships);
    }

    public function store(
        StoreAdminMembershipRequest $request,
        MembershipActivationService $activations,
        MembershipResolver $resolver
    ): JsonResponse {
        $plan = Plan::findOrFail($request->validated('plan_id'));

        if ($request->validated('company_id')) {
            $ownerType = OwnerType::Company;
            $ownerId = (int) $request->validated('company_id');
        } else {
            $ownerType = OwnerType::User;
            $ownerId = (int) $request->validated('user_id');
        }

        // An owner with a live membership would end up holding two at once,
        // and MembershipResolver would silently pick whichever it saw first.
        if ($resolver->activeForOwner($ownerType, $ownerId) !== null) {
            return response()->json(['message' => __('api.admin.membership_already_active')], 422);
        }

        try {
            $membership = $activations->activate(
                $plan,
                $ownerType,
                $ownerId,
                charge: false,
                auditAction: 'membership_activated_by_operations',
                branchId: $request->validated('branch_id'),
                seats: (int) ($request->validated('seats') ?? 1),
                overrideCapacity: (bool) $request->validated('override_capacity', false),
                deskIds: $request->validated('seat_desk_ids', []),
            );
        } catch (DeskAssignmentRefusedException $e) {
            throw ValidationException::withMessages(['seat_desk_ids' => __($e->reason)]);
        } catch (SeatPoolExhaustedException $e) {
            return response()->json([
                'message' => __('api.membership.seat_pool_full'),
                'first_shortfall_at' => $e->firstShortfall->toIso8601String(),
            ], 422);
        }

        return response()->json([
            'data' => new MembershipResource($membership->load(['plan', 'seatDesks'])),
        ], 201);
    }

    /**
     * Ties a Dedicated Desk subscription to its desks, replacing any it had —
     * how one bought in the app gets its desk (docs/decisions/coworking-seat-pool.md).
     */
    public function assignDesks(
        AssignMembershipDesksRequest $request,
        Membership $membership,
        DedicatedDeskService $desks,
    ): JsonResponse {
        try {
            $desks->assign($membership, $request->validated('seat_desk_ids'));
        } catch (DeskAssignmentRefusedException $e) {
            throw ValidationException::withMessages(['seat_desk_ids' => __($e->reason)]);
        }

        return response()->json(['message' => __('api.membership.desks_assigned')]);
    }

    /**
     * Gives each membership its owner's display name — a member's name or a
     * company's legal name — in two queries rather than one per row.
     *
     * @param  Collection<int, Membership>  $memberships
     */
    private function nameOwners(Collection $memberships): void
    {
        $ids = fn (OwnerType $type) => $memberships->where('owner_type', $type)->pluck('owner_id')->unique()->all();
        $names = [
            OwnerType::User->value => User::query()->whereKey($ids(OwnerType::User))->pluck('name', 'id'),
            OwnerType::Company->value => Company::query()->whereKey($ids(OwnerType::Company))->pluck('legal_name', 'id'),
        ];

        $memberships->each(fn (Membership $membership) => $membership->setAttribute(
            'owner_name',
            $names[$membership->owner_type->value][$membership->owner_id] ?? null,
        ));
    }
}
