<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Pricing\Services\CouponHistory;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OfferReportRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * GET admin/reports/offers — which offers ran in a period: each stretch it
 * was switched on and inside its dates, on the terms it had then, and how
 * many operations it priced and the points it took off in the period
 * (CouponHistory). Read by admin and operations, like the discount report.
 */
class OfferReportController extends Controller
{
    public function index(OfferReportRequest $request, CouponHistory $history, SettingService $settings): JsonResponse
    {
        $timezone = (string) $settings->get('app.timezone', 'Asia/Damascus');
        $from = CarbonImmutable::parse($request->validated('from'), $timezone)->startOfDay();
        $to = CarbonImmutable::parse($request->validated('to'), $timezone)->addDay()->startOfDay();

        return response()->json([
            'data' => $history->running($from, $to, $request->validated('type', 'offer') !== 'coupon'),
        ]);
    }
}
