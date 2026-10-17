<?php

namespace Add\Hr\Payroll\Http\Controllers\SelfService;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Http\Middleware\EnsureActiveEmployee;
use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Payroll\Http\Requests\RequestLoanRequest;
use Add\Hr\Payroll\Http\Resources\LoanResource;
use Add\Hr\Payroll\Http\Resources\PayslipResource;
use Add\Hr\Payroll\Models\Compensation;
use Add\Hr\Payroll\Models\Loan;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Payroll\Services\LoanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

/**
 * hr/me: my payslips (only once their run is approved), my loans, and the
 * data for an employment or salary certificate.
 */
class MyPayController extends Controller
{
    public function payslips(Request $request): AnonymousResourceCollection
    {
        return PayslipResource::collection(
            $this->published($request)->with('run')->latest('id')->paginate(24)
        );
    }

    public function payslip(Request $request, string $payslip): PayslipResource
    {
        return new PayslipResource($this->published($request)->with(['lines', 'run'])->findOrFail($payslip));
    }

    public function loans(Request $request): AnonymousResourceCollection
    {
        return LoanResource::collection(
            Loan::query()->where('employee_id', $this->employee($request)->id)->with('schedule')->latest('id')->get()
        );
    }

    public function requestLoan(RequestLoanRequest $request, LoanService $loans): LoanResource
    {
        return new LoanResource($loans->request($this->employee($request), $request->validated(), $request->user()?->getAuthIdentifier())->load('schedule'));
    }

    public function cancelLoan(Request $request, string $loan, LoanService $loans): JsonResponse
    {
        $loans->cancel(Loan::query()->where('employee_id', $this->employee($request)->id)->findOrFail($loan));

        return response()->json(['message' => __('hr::time.cancelled')]);
    }

    /**
     * The facts an employment certificate states, for the client to lay out.
     */
    public function employmentCertificate(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        HrAudit::record('certificate_generated', $employee, ['kind' => 'employment']);

        return response()->json(['data' => $this->facts($employee)]);
    }

    /**
     * The same, with current monthly pay (cash earnings) and the last net.
     */
    public function salaryCertificate(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $today = CarbonImmutable::today();
        HrAudit::record('certificate_generated', $employee, ['kind' => 'salary']);

        $pay = Compensation::query()
            ->where('employee_id', $employee->id)
            ->overlapping($today, $today)
            ->whereHas('component', fn ($q) => $q->where('kind', PayComponentKind::Earning->value))
            ->with('component')
            ->get()
            ->map(fn (Compensation $line) => ['code' => $line->component->code, 'name' => $line->component->name, 'amount' => $line->amount, 'currency' => $line->currency])
            ->values();
        $last = Payslip::query()->where('employee_id', $employee->id)->whereNotNull('published_at')->with('run')->latest('id')->first();

        return response()->json(['data' => [
            ...$this->facts($employee),
            'monthly_pay' => $pay,
            'last_payslip' => $last === null ? null : [
                'period' => sprintf('%04d-%02d', $last->run->year, $last->run->month),
                'gross' => $last->gross,
                'net' => $last->net,
                'currency' => $last->currency,
            ],
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(Employee $employee): array
    {
        $assignment = $employee->currentAssignment();

        return [
            'employee_number' => $employee->employee_number,
            'name' => $employee->name,
            'hired_on' => $employee->hired_on->toDateString(),
            'job_title' => $assignment?->resolvedTitle(),
            'org_unit' => $assignment?->orgUnit?->name,
            'contract_type' => $employee->currentContract()?->type->value,
            'status' => $employee->status->value,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function published(Request $request)
    {
        return Payslip::query()->where('employee_id', $this->employee($request)->id)->whereNotNull('published_at');
    }

    private function employee(Request $request): Employee
    {
        return $request->attributes->get(EnsureActiveEmployee::ATTRIBUTE);
    }
}
