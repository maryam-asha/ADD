<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Services\UnlockService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UnlockRequest;
use Illuminate\Http\JsonResponse;

class AccessUnlockController extends Controller
{
    public function unlock(UnlockRequest $request, UnlockService $service): JsonResponse
    {
        try {
            $service->unlock($request->user(), $request->validated('qr_value'));
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['message' => __('api.access.unlocked')]);
    }
}
