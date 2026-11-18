<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Reports\Services\Dashboard\FinanceOverview;
use App\Domain\Reports\Services\Dashboard\MembersOverview;
use App\Domain\Reports\Services\Dashboard\OperationsOverview;
use App\Domain\Reports\Services\Dashboard\SpacesOverview;
use App\Domain\Reports\ValueObjects\DashboardPeriod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\DashboardRequest;
use App\Http\Requests\Reports\DashboardSpacesRequest;
use App\Http\Requests\Reports\ReportPeriodRequest;
use App\Support\Time\LocalTime;
use Illuminate\Http\JsonResponse;

/**
 * The dashboard's home page, one read-only section per permission
 * (`dashboard.operations|finance|members|spaces`), so each role sees what its work
 * needs. No model of its own, so not an AdminResourceController -
 * registered by hand in PermissionSyncService.
 */
class DashboardController extends Controller
{
    public function operations(DashboardRequest $request, OperationsOverview $overview, LocalTime $time): JsonResponse
    {
        return response()->json(['data' => $overview->for($this->period($request, $time), $this->branch($request))]);
    }

    /** Centre-wide: money belongs to no branch. */
    public function finance(ReportPeriodRequest $request, FinanceOverview $overview, LocalTime $time): JsonResponse
    {
        return response()->json(['data' => $overview->for($this->period($request, $time))]);
    }

    public function members(DashboardRequest $request, MembersOverview $overview, LocalTime $time): JsonResponse
    {
        return response()->json(['data' => $overview->for($this->period($request, $time), $this->branch($request))]);
    }

    public function spaces(DashboardSpacesRequest $request, SpacesOverview $overview, LocalTime $time): JsonResponse
    {
        return response()->json(['data' => $overview->for(
            $this->period($request, $time),
            $this->branch($request),
            $request->filled('space_type') ? SpaceType::from($request->input('space_type')) : null,
            $request->filled('space_id') ? Space::query()->findOrFail($request->integer('space_id')) : null,
        )]);
    }

    private function period(ReportPeriodRequest $request, LocalTime $time): DashboardPeriod
    {
        return DashboardPeriod::between($request->input('from'), $request->input('to'), $time);
    }

    private function branch(DashboardRequest $request): ?Branch
    {
        return $request->filled('branch_id') ? Branch::query()->findOrFail($request->integer('branch_id')) : null;
    }
}
