<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Identity\Enums\DeviceSurface;
use App\Http\Requests\Dashboard\DashboardDeviceTokenRequest;
use App\Services\Notification\DeviceTokenService;
use Illuminate\Http\JsonResponse;

/**
 * The browser the dashboard runs in, registered for push by the account
 * signed in to it — staff and provider alike — and forgotten at sign-out.
 * Its pushes are this account's dashboard notices, never its member ones.
 */
trait RegistersDashboardDeviceToken
{
    public function update(DashboardDeviceTokenRequest $request, DeviceTokenService $devices): JsonResponse
    {
        $devices->register($request->user(), $request->validated('token'), $request->deviceDetails(), null, DeviceSurface::Dashboard);

        return response()->json(['message' => __('api.notification.device_token_updated')]);
    }

    public function destroy(DashboardDeviceTokenRequest $request, DeviceTokenService $devices): JsonResponse
    {
        $devices->forget($request->user(), $request->validated('token'), DeviceSurface::Dashboard);

        return response()->json(['message' => __('api.notification.device_token_removed')]);
    }
}
