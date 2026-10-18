<?php

namespace Add\Hr\Payroll\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Contracts\PayrollAccountingExporter;
use Add\Hr\Payroll\Http\Requests\AddAdjustmentRequest;
use Add\Hr\Payroll\Http\Requests\OpenPayrollRunRequest;
use Add\Hr\Payroll\Http\Requests\PayRunRequest;
use Add\Hr\Payroll\Http\Resources\PayrollRunResource;
use Add\Hr\Payroll\Http\Resources\PayslipResource;
use Add\Hr\Payroll\Models\PayrollAdjustment;
use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Payroll\Services\PayrollCalculator;
use Add\Hr\Payroll\Services\PayrollRunService;
use Add\Hr\Payroll\Services\PayrollVariance;
use Add\Hr\Support\HrException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayrollRunController extends Controller
{
    public function __construct(private readonly PayrollRunService $runs) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return PayrollRunResource::collection(
            PayrollRun::query()
                ->when($request->integer('year') ?: null, fn ($q, int $year) => $q->where('year', $year))
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')
                ->paginate(25)
        );
    }

    public function store(OpenPayrollRunRequest $request): PayrollRunResource
    {
        return new PayrollRunResource($this->runs->open($request->validated(), $request->user()?->getAuthIdentifier()));
    }

    public function show(PayrollRun $payrollRun): PayrollRunResource
    {
        return new PayrollRunResource($payrollRun->load('payslips'));
    }

    public function destroy(PayrollRun $payrollRun): Response
    {
        $this->runs->cancel($payrollRun);

        return response()->noContent();
    }

    public function calculate(Request $request, PayrollRun $payrollRun, PayrollCalculator $calculator): JsonResponse
    {
        $calculator->calculate($payrollRun, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::payroll.calculated')]);
    }

    public function review(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->runs->review($payrollRun, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::payroll.reviewed')]);
    }

    public function reconcile(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->runs->confirmReconciliation($payrollRun, $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::payroll.reconciled')]);
    }

    public function approve(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->runs->approve($payrollRun, $request->user());

        return response()->json(['message' => __('hr::payroll.approved')]);
    }

    public function pay(PayRunRequest $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->runs->pay($payrollRun, $request->validated('payment_reference'), $request->user()?->getAuthIdentifier());

        return response()->json(['message' => __('hr::payroll.paid')]);
    }

    public function post(PayrollRun $payrollRun): JsonResponse
    {
        $this->runs->markPosted($payrollRun);

        return response()->json(['message' => __('hr::payroll.posted')]);
    }

    public function variance(PayrollRun $payrollRun, PayrollVariance $variance): JsonResponse
    {
        return response()->json(['data' => $variance->for($payrollRun)]);
    }

    public function payslips(PayrollRun $payrollRun): AnonymousResourceCollection
    {
        return PayslipResource::collection($payrollRun->payslips()->orderBy('employee_id')->paginate(100));
    }

    public function payslip(Payslip $payslip): PayslipResource
    {
        return new PayslipResource($payslip->load(['lines', 'run']));
    }

    public function adjustments(PayrollRun $payrollRun): JsonResponse
    {
        return response()->json(['data' => $payrollRun->adjustments()->with('component')->orderBy('employee_id')->get()->map(fn (PayrollAdjustment $adjustment) => [
            'id' => $adjustment->id,
            'employee_id' => $adjustment->employee_id,
            'component' => ['id' => $adjustment->component->id, 'code' => $adjustment->component->code],
            'amount' => $adjustment->amount,
            'currency' => $adjustment->currency,
            'note' => $adjustment->note,
        ])->values()]);
    }

    public function addAdjustment(AddAdjustmentRequest $request, PayrollRun $payrollRun): JsonResponse
    {
        $adjustment = $this->runs->addAdjustment($payrollRun, $request->validated(), $request->user()?->getAuthIdentifier());

        return response()->json(['data' => ['id' => $adjustment->id]], 201);
    }

    public function removeAdjustment(PayrollAdjustment $adjustment): Response
    {
        $this->runs->removeAdjustment($adjustment);

        return response()->noContent();
    }

    /**
     * The journal of an approved run, as CSV (audited) or JSON.
     */
    public function journal(Request $request, PayrollRun $payrollRun, PayrollAccountingExporter $exporter): JsonResponse|StreamedResponse
    {
        if (! $payrollRun->isLocked()) {
            HrException::fail('hr::payroll.not_postable');
        }

        $rows = $exporter->rows($payrollRun);

        if ($request->query('format') !== 'csv') {
            return response()->json(['data' => $rows]);
        }

        HrAudit::record('report_exported', $payrollRun, ['report' => 'payroll_journal']);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['account', 'cost_center', 'employee_number', 'debit', 'credit', 'currency']);

            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }

            fclose($out);
        }, sprintf('payroll-journal-%04d-%02d-%d.csv', $payrollRun->year, $payrollRun->month, $payrollRun->id), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
