<?php

namespace Add\Hr\Payroll\Http\Resources;

use Add\Hr\Payroll\Models\PayrollRun;
use Add\Hr\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayrollRun
 */
class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'year' => $this->year,
            'month' => $this->month,
            'kind' => $this->kind->value,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'employee_ids' => $this->employee_ids,
            'exchange_rates' => $this->exchange_rates === null ? null : (object) $this->exchange_rates,
            'parallel_run' => $this->parallel_run,
            'reconciled_at' => $this->reconciled_at?->toIso8601String(),
            'notes' => $this->notes,
            'totals' => $this->when($this->relationLoaded('payslips'), fn () => [
                'payslips' => $this->payslips->count(),
                'gross' => Decimal::add('0', ...$this->payslips->pluck('gross')->all()),
                'deductions' => Decimal::add('0', ...$this->payslips->pluck('deductions')->all()),
                'net' => Decimal::add('0', ...$this->payslips->pluck('net')->all()),
                'employer_cost' => Decimal::add('0', ...$this->payslips->pluck('employer_cost')->all()),
                'warnings' => $this->payslips->filter(fn ($slip) => ! empty($slip->warnings))->count(),
            ]),
            'calculated_at' => $this->calculated_at?->toIso8601String(),
            'calculated_by' => $this->calculated_by,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->reviewed_by,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approved_by' => $this->approved_by,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'posted_at' => $this->posted_at?->toIso8601String(),
        ];
    }
}
