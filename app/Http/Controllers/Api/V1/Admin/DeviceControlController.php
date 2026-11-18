<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Services\AccessDeviceSync;
use App\Domain\Access\Services\StaffDoorService;
use App\Domain\Foundation\Models\Device;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * What the dashboard does to locks beyond editing their rows: open or lock a
 * door, refresh every lock and gateway from the TTLock account, and list
 * that account so a device row can be linked to a real lock. Nothing of a
 * lock's vendor key material is ever returned.
 */
class DeviceControlController extends Controller
{
    public function unlock(Request $request, Device $device, StaffDoorService $doors): JsonResponse
    {
        return $this->act(fn () => $doors->unlock($device, $request->user()), 'api.access.unlocked');
    }

    public function lock(Request $request, Device $device, StaffDoorService $doors): JsonResponse
    {
        return $this->act(fn () => $doors->lock($device, $request->user()), 'api.access.locked');
    }

    public function sync(AccessDeviceSync $sync): JsonResponse
    {
        if (! $sync->configured()) {
            return response()->json(['message' => __('api.access.ttlock_not_configured')], 422);
        }

        try {
            $counts = $sync->sync();
        } catch (\Throwable $e) {
            Log::error('[Access] TTLock device sync failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => __('api.access.ttlock_unavailable')], 503);
        }

        return response()->json(['message' => __('api.access.devices_synced', $counts)]);
    }

    public function account(AccessDeviceSync $sync): JsonResponse
    {
        if (! $sync->configured()) {
            return response()->json(['message' => __('api.access.ttlock_not_configured')], 422);
        }

        try {
            return response()->json(['data' => $sync->account()]);
        } catch (\Throwable $e) {
            Log::error('[Access] TTLock account listing failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => __('api.access.ttlock_unavailable')], 503);
        }
    }

    private function act(callable $action, string $doneKey): JsonResponse
    {
        try {
            $action();
        } catch (LockAccessDeniedException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        return response()->json(['message' => __($doneKey)]);
    }
}
