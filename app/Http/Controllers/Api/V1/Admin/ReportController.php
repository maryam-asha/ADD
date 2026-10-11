<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Reports\Services\ProvidersReport;
use App\Domain\Reports\Services\RevenueReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\RevenueReportRequest;
use Illuminate\Http\JsonResponse;

/**
 * Admin reports over invoices (docs/decisions/provider-reports.md), gated by
 * the `reports.view` permission. No model of its own, so not an
 * AdminResourceController - registered by hand in PermissionSyncService.
 */
class ReportController extends Controller
{
    public function revenue(RevenueReportRequest $request, RevenueReport $report): JsonResponse
    {
        return response()->json(['data' => $report->for(
            $request->input('from'),
            $request->input('to'),
            $request->filled('provider_id') ? $request->integer('provider_id') : null,
        )]);
    }

    public function providers(ProvidersReport $report): JsonResponse
    {
        return response()->json(['data' => $report->all()]);
    }
}
