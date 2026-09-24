<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\InsufficientResourceQuotaException;
use App\Domain\Membership\Models\ResourceType;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\ResourceQuotaService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\StoreResourceConsumptionRequest;
use Illuminate\Http\JsonResponse;

/**
 * Reception's manual-entry recording of natural-unit resource consumption
 * (Printing, Internet, ...) against a member/company's active membership —
 * the missing reception-facing endpoint over ResourceQuotaService, same
 * shape as WalletTopUpController (docs/decisions/resource-quota-design.md).
 */
class ResourceConsumptionController extends Controller
{
    use LogsSensitiveActions;

    public function store(
        StoreResourceConsumptionRequest $request,
        MembershipResolver $resolver,
        ResourceQuotaService $quota,
    ): JsonResponse {
        [$ownerType, $ownerId] = $request->validated('company_id')
            ? [OwnerType::Company, (int) $request->validated('company_id')]
            : [OwnerType::User, (int) $request->validated('user_id')];

        $membership = $ownerType === OwnerType::Company
            ? $resolver->activeForOwner(OwnerType::Company, $ownerId)
            : $resolver->activeFor(User::findOrFail($ownerId));

        if ($membership === null) {
            abort(422, __('api.reception.no_active_membership'));
        }

        $resourceType = ResourceType::findOrFail($request->validated('resource_type_id'));

        $grant = $membership->plan?->resourceGrants()->where('resource_type_id', $resourceType->id)->first();

        if ($grant === null) {
            abort(422, __('api.reception.resource_grant_not_found'));
        }

        try {
            $consumption = $quota->consume(
                $grant,
                $membership,
                (float) $request->validated('quantity'),
                $request->user(),
                $request->validated('note')
            );
        } catch (InsufficientResourceQuotaException) {
            abort(422, __('api.reception.resource_quota_insufficient'));
        }

        $this->logSensitiveAction('resource_consumption_recorded', $consumption, [
            'membership_id' => $membership->id,
            'resource_type_id' => $resourceType->id,
            'quantity' => (float) $consumption->quantity,
        ]);

        return response()->json([
            'data' => [
                'id' => $consumption->id,
                'resource_type_id' => $resourceType->id,
                'resource_type_slug' => $resourceType->slug,
                'quantity' => (float) $consumption->quantity,
                'remaining' => $quota->remainingFor($membership, $resourceType),
                'membership_id' => $membership->id,
                'note' => $consumption->note,
                'created_at' => $consumption->created_at?->toISOString(),
            ],
        ], 201);
    }
}
