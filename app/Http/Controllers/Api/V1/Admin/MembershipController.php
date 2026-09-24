<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\MembershipActivationService;
use App\Domain\Membership\Services\MembershipResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminMembershipRequest;
use App\Http\Resources\MembershipResource;
use Illuminate\Http\JsonResponse;

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

        $membership = $activations->activate(
            $plan,
            $ownerType,
            $ownerId,
            charge: false,
            auditAction: 'membership_activated_by_operations',
        );

        return response()->json([
            'data' => new MembershipResource($membership->load('plan')),
        ], 201);
    }
}
