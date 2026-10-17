<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Payroll\Enums\InstallmentStatus;
use Add\Hr\Support\Casts\DecimalString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One month's repayment of a loan.
 */
class LoanInstallment extends Model
{
    protected $table = 'hr_loan_installments';

    protected $fillable = [
        'loan_id',
        'year',
        'month',
        'amount',
        'status',
        'payslip_id',
        'note',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'amount' => DecimalString::class,
        'status' => InstallmentStatus::class,
    ];

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
