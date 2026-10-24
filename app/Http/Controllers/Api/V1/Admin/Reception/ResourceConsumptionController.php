<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Identity\Models\User;
use App\Domain\Inventory\ReceptionStockLocation;
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
 * A type that uses up stock (printing: paper) issues it in the same
 * transaction, from `stock_location` or the branch of the operator's open
 * cash register; a shelf that cannot cover it refuses the consumption.
 */
class ResourceConsumptionController extends Controller
{
    use LogsSensitiveActions;

    public function store(
        StoreResourceConsumptionRequest $request,
        MembershipResolver $resolver,
        ResourceQuotaService $quota,
        CoworkingSessionService $sessions,
        ReceptionStockLocation $stockLocations,
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
            $stockFrom = $resourceType->inventory_item_id === null
                ? null
                : $stockLocations->resolve($request->validated('stock_location'), $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        try {
            $consumption = $quota->consume(
                $grant,
                $membership,
                (float) $request->validated('quantity'),
                $request->user(),
                $request->validated('note'),
                $stockFrom,
            );
        } catch (InsufficientResourceQuotaException) {
            abort(422, __('api.reception.resource_quota_insufficient'));
        }

        // Printing or internet used during an open coworking session belongs
        // on that session's report (docs/decisions/coworking-self-service-sessions.md).
        if ($ownerType === OwnerType::User) {
            $open = $sessions->openSessionFor(User::findOrFail($ownerId));

            if ($open !== null) {
                $consumption->sessionable()->associate($open)->save();
            }
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
