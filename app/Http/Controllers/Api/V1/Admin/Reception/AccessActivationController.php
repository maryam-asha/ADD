<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class AccessActivationController extends Controller
{
    public function activate(AccessGrant $accessGrant, PasscodeIssuanceService $issuance): JsonResponse
    {
        try {
            $issuance->activate($accessGrant);
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['message' => __('api.access.activated')]);
    }
}
