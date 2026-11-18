<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Pricing\Services\DiscountReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DiscountReportRequest;
use Illuminate\Http\JsonResponse;

/**
 * Every operation that carried a discount or a saving in a period, with
 * its status, and the totals over the ones that count
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 * Read by admin and operations, like the coupon reads (E5). Not an
 * AdminResourceController: it has no model of its own.
 */
class DiscountReportController extends Controller
{
    public function index(DiscountReportRequest $request, DiscountReport $report): JsonResponse
    {
        $filters = $request->safe()->except('per_page');
        $page = $report->page($filters, $request->integer('per_page', 15));

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'totals' => $report->totals($filters),
            ],
        ]);
    }
}
