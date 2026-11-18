<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Membership\Models\Plan;
use App\Domain\Membership\Services\PackageBrowseService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    /**
     * The package catalog — see `MembershipController::current()` for the
     * member's own subscription and usage, a separate endpoint for the
     * separate "My Subscription" screen. `features` is capped at 3 per plan
     * (plus `more_features_count`) — `show()` is where a client reads the
     * full list, for the "View details" screen.
     */
    public function index(Request $request, PackageBrowseService $service): JsonResponse
    {
        $data = $service->listAvailablePackages($request->user(), $request->input('module'));

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * One plan's full details (untruncated `features`) — "View details".
     */
    public function show(Plan $plan, Request $request, PackageBrowseService $service): JsonResponse
    {
        $data = $service->getPackageDetails($plan, $request->user());

        abort_if($data === null, 404);

        return response()->json([
            'data' => $data,
        ]);
    }
}
