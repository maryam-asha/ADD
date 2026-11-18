<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Domain\Reports\Services\ProviderCompaniesReport;
use App\Domain\Reports\Services\ProviderInvoiceReport;
use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportPeriodRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The provider's own reports (docs/decisions/provider-reports.md): scoped to its provider, always. */
class ProviderReportController extends Controller
{
    use ActsForProvider;

    public function invoices(ReportPeriodRequest $request, ProviderInvoiceReport $report): JsonResponse
    {
        return response()->json(['data' => $report->for(
            $this->provider($request), $request->input('from'), $request->input('to')
        )]);
    }

    public function companies(Request $request, ProviderCompaniesReport $report): JsonResponse
    {
        return response()->json(['data' => $report->for($this->provider($request))]);
    }
}
