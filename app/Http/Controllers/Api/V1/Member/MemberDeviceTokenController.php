<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateDeviceTokenRequest;
use App\Services\Notification\DeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class MemberDeviceTokenController extends Controller
{
    public function __construct(
        private readonly DeviceTokenService $devices,
    ) {}

    public function update(UpdateDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $accessToken = $user->currentAccessToken();

        $this->devices->register(
            $user,
            $request->input('fcm_token') ?? $request->input('token'),
            $request->deviceDetails(),
            $accessToken instanceof PersonalAccessToken && $accessToken->exists ? $accessToken->getKey() : null,
        );

        return response()->json(['message' => __('api.notification.device_token_updated')]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $request->input('fcm_token') ?? $request->input('token');

        if ($token) {
            $this->devices->forget($user, $token);
        } else {
            $this->devices->forgetAll($user);
        }

        return response()->json(['message' => __('api.notification.device_token_removed')]);
    }
}
