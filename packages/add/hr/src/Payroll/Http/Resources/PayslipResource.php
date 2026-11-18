<?php

namespace Add\Hr\Payroll\Http\Resources;

use Add\Hr\Payroll\Models\Payslip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payslip
 */
class PayslipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payroll_run_id' => $this->payroll_run_id,
            'period' => $this->whenLoaded('run', fn () => sprintf('%04d-%02d', $this->run->year, $this->run->month)),
            'employee_id' => $this->employee_id,
            'employee' => $this->snapshot,
            'currency' => $this->currency,
            'gross' => $this->gross,
            'deductions' => $this->deductions,
            'net' => $this->net,
            'employer_cost' => $this->when($request->routeIs('hr.payroll-runs.*', 'hr.payslips.*'), $this->employer_cost),
            'employed_days' => $this->employed_days,
            'days_in_month' => $this->days_in_month,
            'warnings' => $this->warnings,
            'published_at' => $this->published_at?->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'code' => $line->code,
                'name' => $line->name,
                'kind' => $line->kind->value,
                'basis' => $line->basis,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'amount' => $line->amount,
                'original_amount' => $line->original_amount,
                'original_currency' => $line->original_currency,
            ])->values()),
        ];
    }
}
