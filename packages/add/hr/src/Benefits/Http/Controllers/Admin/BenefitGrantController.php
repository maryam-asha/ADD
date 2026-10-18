<?php

namespace Add\Hr\Benefits\Http\Controllers\Admin;

use Add\Hr\Benefits\Enums\BenefitGrantStatus;
use Add\Hr\Benefits\Http\Requests\RunBenefitGrantsRequest;
use Add\Hr\Benefits\Http\Resources\BenefitGrantResource;
use Add\Hr\Benefits\Models\BenefitGrant;
use Add\Hr\Benefits\Services\BenefitGranter;
use Add\Hr\Benefits\Services\EmployeeCostReport;
use Add\Hr\Contracts\HrClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

class BenefitGrantController extends Controller
{
    public function __construct(private readonly BenefitGranter $granter) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(BenefitGrantStatus::class)],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        return BenefitGrantResource::collection(
            BenefitGrant::query()->with(['benefit', 'employee'])
                ->when($request->query('benefit_id'), fn ($q, $id) => $q->where('benefit_id', $id))
                ->when($request->query('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->when($request->query('month'), function ($q, $month) {
                    $start = CarbonImmutable::createFromFormat('!Y-m', $month);
                    $q->whereBetween('granted_on', [$start->toDateString(), $start->endOfMonth()->toDateString()]);
                })
                ->latest('granted_on')
                ->latest('id')
                ->paginate(50)
        );
    }

    /**
     * Grants a month now instead of waiting for the daily job (after adding
     * an assignment, or to catch up a past month).
     */
    public function run(RunBenefitGrantsRequest $request, HrClock $clock): JsonResponse
    {
        $month = $request->validated('month') !== null
            ? CarbonImmutable::createFromFormat('!Y-m', $request->validated('month'), $clock->timezone())
            : CarbonImmutable::now($clock->timezone())->startOfMonth();

        return response()->json(['data' => ['month' => $month->format('Y-m'), ...$this->granter->run($month)]]);
    }

    public function retry(BenefitGrant $grant): BenefitGrantResource
    {
        return new BenefitGrantResource($this->granter->retry($grant)->load(['benefit', 'employee']));
    }

    public function cancel(Request $request, BenefitGrant $grant): JsonResponse
    {
        $this->granter->cancel($grant, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::benefits.grant_cancelled')]);
    }

    /**
     * Each employee's full cost for a month: approved payroll plus benefits.
     */
    public function employeeCost(Request $request, EmployeeCostReport $report): JsonResponse
    {
        $request->validate(['month' => ['required', 'date_format:Y-m']]);

        return response()->json(['data' => $report->month(CarbonImmutable::createFromFormat('!Y-m', $request->query('month')))]);
    }
}
