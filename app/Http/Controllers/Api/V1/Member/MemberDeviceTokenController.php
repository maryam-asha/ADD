<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use App\Http\Requests\Member\UpdateDeviceTokenRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberDeviceTokenController extends Controller
{
    public function update(UpdateDeviceTokenRequest $request): JsonResponse
    {
        $user = $request->user();
        $token = $request->input('fcm_token') ?? $request->input('token');
        $platform = $request->input('platform');

        $user->deviceTokens()->updateOrCreate(
            ['token' => $token],
            [
                'platform' => $platform,
                'last_used_at' => now(),
            ]
        );

        $user->update(['fcm_token' => $token]);

        return response()->json(['message' => __('api.notification.device_token_updated')]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $request->input('fcm_token') ?? $request->input('token');

        if ($token) {
            $user->deviceTokens()->where('token', $token)->delete();
            if ($user->fcm_token === $token) {
                $user->update(['fcm_token' => $user->deviceTokens()->latest('id')->value('token')]);
            }
        } else {
            $user->deviceTokens()->delete();
            $user->update(['fcm_token' => null]);
        }

        return response()->json(['message' => __('api.notification.device_token_removed')]);
    }
}
