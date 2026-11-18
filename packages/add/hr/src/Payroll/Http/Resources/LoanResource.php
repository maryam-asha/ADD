<?php

namespace Add\Hr\Payroll\Http\Resources;

use Add\Hr\Payroll\Enums\InstallmentStatus;
use Add\Hr\Payroll\Models\Loan;
use Add\Hr\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Loan
 */
class LoanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee' => $this->whenLoaded('employee', fn () => ['id' => $this->employee->id, 'name' => $this->employee->name]),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'installments' => $this->installments,
            'installment_amount' => $this->installment_amount,
            'first_month' => sprintf('%04d-%02d', $this->first_year, $this->first_month),
            'reason' => $this->reason,
            'status' => $this->status->value,
            'approval_request_id' => $this->approval_request_id,
            'outstanding' => Decimal::add('0', ...$this->schedule->where('status', InstallmentStatus::Due)->pluck('amount')->all()),
            'schedule' => $this->schedule->map(fn ($installment) => [
                'month' => sprintf('%04d-%02d', $installment->year, $installment->month),
                'amount' => $installment->amount,
                'status' => $installment->status->value,
            ])->values(),
        ];
    }
}
