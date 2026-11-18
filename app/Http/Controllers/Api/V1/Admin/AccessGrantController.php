<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccessGrantResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Door codes as staff manage them: which are on a lock and for whom, and
 * stopping one by hand. Paginated like the access log — every booking on a
 * locked room adds one.
 */
class AccessGrantController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'lock_id' => ['nullable', 'integer'],
            'booking_id' => ['nullable', 'integer'],
            'office_lease_id' => ['nullable', 'integer'],
            // `live` (issued or activated), or one stored status.
            'status' => ['nullable', Rule::in(['live', ...array_column(AccessGrantStatus::cases(), 'value')])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AccessGrant::query()->with(['lock.space', 'revokedBy'])->latest('id')
            ->when($filters['lock_id'] ?? null, fn ($q, $id) => $q->where('lock_id', $id))
            ->when($filters['booking_id'] ?? null, fn ($q, $id) => $q->where('source_type', 'booking')->where('source_id', $id))
            ->when($filters['office_lease_id'] ?? null, fn ($q, $id) => $q->where('office_lease_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $status === 'live'
                ? $q->whereIn('status', [AccessGrantStatus::Issued, AccessGrantStatus::Activated])
                : $q->where('status', $status));

        return AccessGrantResource::collection($query->paginate($filters['per_page'] ?? 25));
    }

    public function revoke(Request $request, AccessGrant $accessGrant, PasscodeIssuanceService $issuance): JsonResponse
    {
        try {
            $issuance->revokeByStaff($accessGrant->loadMissing('lock'), $request->user());
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['message' => __('api.access.grant_revoked')]);
    }
}
