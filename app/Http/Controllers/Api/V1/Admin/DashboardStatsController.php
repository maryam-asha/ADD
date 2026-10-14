<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Reports\Services\Dashboard\StatsOverview;
use App\Domain\Reports\ValueObjects\DashboardPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\DashboardStatsRequest;
use App\Support\Time\LocalTime;
use Illuminate\Http\JsonResponse;

/**
 * The statistics cards of the dashboard's home page, one action per card,
 * each behind the permission of the section it belongs to
 * (`dashboard.operations|members`). No model of its own, so not an
 * AdminResourceController - registered by hand in PermissionSyncService.
 */
class DashboardStatsController extends Controller
{
    public function __construct(private readonly StatsOverview $stats) {}

    public function bookingsByStatus(DashboardStatsRequest $request, LocalTime $time): JsonResponse
    {
        return response()->json(['data' => $this->stats->bookingsByStatus(
            $this->period($request, $time),
            $this->branch($request),
            $request->filled('space_type') ? SpaceType::from($request->input('space_type')) : null,
        )]);
    }

    public function serviceRequests(DashboardStatsRequest $request, LocalTime $time): JsonResponse
    {
        return response()->json(['data' => $this->stats->serviceRequests($this->period($request, $time), $this->branch($request))]);
    }

    public function dailyBookings(DashboardStatsRequest $request, LocalTime $time): JsonResponse
    {
        return response()->json(['data' => $this->stats->dailyBookings($this->period($request, $time), $this->branch($request))]);
    }

    public function dailyMembers(DashboardStatsRequest $request, LocalTime $time): JsonResponse
    {
        return response()->json(['data' => $this->stats->dailyMembers($this->period($request, $time), $this->branch($request))]);
    }

    public function appUsers(): JsonResponse
    {
        return response()->json(['data' => $this->stats->appUsers()]);
    }

    private function period(DashboardStatsRequest $request, LocalTime $time): DashboardPeriod
    {
        return DashboardPeriod::between($request->input('from'), $request->input('to'), $time);
    }

    private function branch(DashboardStatsRequest $request): ?Branch
    {
        return $request->filled('branch_id') ? Branch::query()->findOrFail($request->integer('branch_id')) : null;
    }
}
