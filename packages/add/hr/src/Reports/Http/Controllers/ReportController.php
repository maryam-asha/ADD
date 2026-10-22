<?php

namespace Add\Hr\Reports\Http\Controllers;

use Add\Hr\Authorization\Abilities;
use Add\Hr\Contracts\HrClock;
use Add\Hr\Reports\Services\Dashboard;
use Add\Hr\Reports\Services\HeadcountReport;
use Add\Hr\Reports\Services\OperationsReports;
use Add\Hr\Reports\Support\CsvExport;
use Add\Hr\Support\LocalDay;
use Add\Hr\Timesheets\Services\TimesheetWeeks;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The combined HR reports. Each is JSON, or a CSV download with
 * `?format=csv`; money appears only to holders of hr.payroll.view.
 */
class ReportController extends Controller
{
    public function __construct(private readonly HrClock $clock) {}

    public function dashboard(Request $request, Dashboard $dashboard): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date']]);

        return response()->json(['data' => $dashboard->build($this->day($data['as_of'] ?? null), $this->withPay($request))]);
    }

    public function headcount(Request $request, HeadcountReport $report): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from', 'before_or_equal:'.CarbonImmutable::parse((string) $request->input('from'))->addMonths(35)->endOfMonth()->toDateString()],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        $result = $report->build($from, $to);

        if (($data['format'] ?? null) === 'csv') {
            return CsvExport::download('headcount', ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                ['month', 'opening', 'hires', 'leavers', 'closing', 'turnover_percent'],
                array_map(fn (array $month) => array_values($month), $result['months']));
        }

        return response()->json(['data' => $result]);
    }

    public function exceptions(Request $request, OperationsReports $reports): JsonResponse|StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        $result = $reports->exceptions($from, $to);

        if (($data['format'] ?? null) === 'csv') {
            return CsvExport::download('attendance_exceptions', ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                ['employee_number', 'employee', 'type', 'total', 'open'],
                array_map(fn (array $row) => [$row['employee']['employee_number'] ?? null, CsvExport::label($row['employee']['name'] ?? null), $row['type'], $row['total'], $row['open']], $result['rows']));
        }

        return response()->json(['data' => $result]);
    }

    public function timesheetCompliance(Request $request, OperationsReports $reports): JsonResponse|StreamedResponse
    {
        $data = $request->validate(['week_start' => ['required', 'date'], 'format' => ['nullable', 'in:json,csv']]);
        $week = TimesheetWeeks::parse($data['week_start']);
        $result = $reports->timesheetCompliance($week);

        if (($data['format'] ?? null) === 'csv') {
            return CsvExport::download('timesheet_compliance', ['week_start' => $week->toDateString()],
                ['employee_number', 'employee', 'status', 'total_hours'],
                array_map(fn (array $row) => [$row['employee']['employee_number'], CsvExport::label($row['employee']['name']), $row['status'], $row['total_hours']], $result['rows']));
        }

        return response()->json(['data' => ['week_start' => $week->toDateString(), ...$result]]);
    }

    public function liabilities(Request $request, OperationsReports $reports): JsonResponse|StreamedResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date'], 'format' => ['nullable', 'in:json,csv']]);
        $asOf = $this->day($data['as_of'] ?? null);
        $result = $reports->liabilities($asOf, $this->withPay($request));

        if (($data['format'] ?? null) === 'csv') {
            $rows = [
                ...array_map(fn (array $row) => ['leave', $row['employee']['employee_number'], CsvExport::label($row['employee']['name']), $row['leave_type']['code'], $row['days'], 'days'], $result['leave']),
                ...array_map(fn (array $row) => ['loan', $row['employee']['employee_number'] ?? null, CsvExport::label($row['employee']['name'] ?? null), null, $row['outstanding'], $row['currency']], $result['loans'] ?? []),
            ];

            return CsvExport::download('liabilities', ['as_of' => $asOf->toDateString()], ['kind', 'employee_number', 'employee', 'leave_type', 'amount', 'unit'], $rows);
        }

        return response()->json(['data' => ['as_of' => $asOf->toDateString(), ...$result]]);
    }

    private function day(?string $value): CarbonImmutable
    {
        return $value === null
            ? LocalDay::today($this->clock)
            : CarbonImmutable::parse($value)->startOfDay();
    }

    private function withPay(Request $request): bool
    {
        return (bool) $request->user()?->can(Abilities::PAYROLL_VIEW);
    }
}
