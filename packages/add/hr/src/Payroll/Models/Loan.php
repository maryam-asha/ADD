<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Enums\LoanStatus;
use Add\Hr\Support\Casts\DecimalString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A salary advance repaid in monthly installments deducted by payroll.
 */
class Loan extends Model
{
    protected $table = 'hr_loans';

    protected $guarded = [];

    protected $casts = [
        'amount' => DecimalString::class,
        'installment_amount' => DecimalString::class,
        'installments' => 'integer',
        'first_year' => 'integer',
        'first_month' => 'integer',
        'status' => LoanStatus::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('year')->orderBy('month');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }
}
