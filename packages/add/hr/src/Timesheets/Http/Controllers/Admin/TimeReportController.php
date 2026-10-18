<?php

namespace Add\Hr\Timesheets\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\PlainNumber;
use Add\Hr\Timesheets\Http\Requests\WeekRangeRequest;
use Add\Hr\Timesheets\Models\CostCenter;
use Add\Hr\Timesheets\Services\CostDistribution;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff time by cost center over a range (approved timesheets only) — the
 * donor / project time report. `?format=csv` downloads it; every export is
 * audited.
 */
class TimeReportController extends Controller
{
    public function byCostCenter(WeekRangeRequest $request, CostDistribution $distribution): JsonResponse|StreamedResponse
    {
        $from = CarbonImmutable::parse($request->validated('from'));
        $to = CarbonImmutable::parse($request->validated('to'));
        $hours = $distribution->approvedHours($from, $to);
        $centers = CostCenter::query()->get()->keyBy('id');
        $employees = Employee::query()->whereIn('id', array_keys($hours))->get()->keyBy('id');
        $only = $request->validated('cost_center_id');

        $rows = [];

        foreach ($hours as $employeeId => $byCenter) {
            foreach ($byCenter as $centerId => $value) {
                if ($only !== null && (int) $only !== $centerId) {
                    continue;
                }

                $rows[] = [
                    'cost_center' => ['id' => $centerId, 'code' => $centers[$centerId]?->code, 'name' => $centers[$centerId]?->name],
                    'employee' => ['id' => $employeeId, 'employee_number' => $employees[$employeeId]?->employee_number, 'name' => $employees[$employeeId]?->name],
                    'hours' => PlainNumber::of($value),
                ];
            }
        }

        usort($rows, fn ($a, $b) => [$a['cost_center']['code'], $a['employee']['id']] <=> [$b['cost_center']['code'], $b['employee']['id']]);

        if ($request->validated('format') === 'csv') {
            HrAudit::record('report_exported', null, ['report' => 'time_by_cost_center', 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['cost_center_code', 'cost_center', 'employee_number', 'employee', 'hours']);

                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row['cost_center']['code'],
                        $this->label($row['cost_center']['name']),
                        $row['employee']['employee_number'],
                        $this->label($row['employee']['name']),
                        $row['hours'],
                    ]);
                }

                fclose($out);
            }, "time-by-cost-center-{$from->toDateString()}-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * @param  array<string, string>|null  $name
     */
    private function label(?array $name): string
    {
        return (string) ($name[app()->getLocale()] ?? $name['en'] ?? $name['ar'] ?? '');
    }
}
