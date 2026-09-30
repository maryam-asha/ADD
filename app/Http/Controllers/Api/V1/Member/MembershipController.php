<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Models\Company;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Exceptions\SeatPoolExhaustedException;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\PackageBrowseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreMembershipRequest;
use App\Http\Resources\MembershipResource;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * docs/architecture/2026-08-08-backend-build-plan.md ("Phase 3 — Membership")
 * — buying a plan debits the buyer's (or their company's) wallet general
 * balance and creates the Membership row in one transaction. Same
 * "self-service checked via the policy in the controller" shape as
 * CompanyWalletAllocationController.
 */
class MembershipController extends Controller
{
    /**
     * The member's own active plan and this-cycle usage, for the "My
     * Subscription" screen — see `PackageController::index()` for the
     * separate package-catalog endpoint. `data: null` (still 200) when the
     * member has no active membership — a normal state, not an error.
     */
    public function current(Request $request, PackageBrowseService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->getCurrentSubscription($request->user()),
        ]);
    }

    public function store(StoreMembershipRequest $request, MembershipActivationService $activations): MembershipResource
    {
        $plan = Plan::findOrFail($request->validated('plan_id'));

        if ($request->validated('company_id')) {
            $company = Company::findOrFail($request->validated('company_id'));

            Gate::authorize('manageMembers', $company);

            $ownerType = OwnerType::Company;
            $ownerId = $company->id;
        } else {
            $ownerType = OwnerType::User;
            $ownerId = $request->user()->id;
        }

        try {
            $membership = $activations->activate($plan, $ownerType, $ownerId);
        } catch (InsufficientBalanceException) {
            // abort() throws, so this never actually returns — keeps the
            // method's return type honestly `MembershipResource` while still
            // producing the same 422 shape as CompanyWalletAllocationController
            // (shouldRenderJsonWhen(true) in bootstrap/app.php turns this into
            // a plain JSON {"message": ...} response).
            abort(422, __('api.wallet.insufficient_balance_for_plan'));
        } catch (SeatPoolExhaustedException $e) {
            // Every co-working seat in the branch is held for part of the
            // plan's period (docs/decisions/coworking-seat-pool.md); nothing
            // was charged.
            throw new HttpResponseException(response()->json([
                'message' => __('api.membership.seat_pool_full'),
                'first_shortfall_at' => $e->firstShortfall->toIso8601String(),
            ], 422));
        }

        return new MembershipResource($membership->load(['plan', 'seatDesks']));
    }
}
