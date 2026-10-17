<?php

namespace Add\Hr\Benefits\Models;

use Add\Hr\Benefits\Enums\BenefitGrantStatus;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Payroll\Models\Payslip;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\DecimalString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One benefit granted to one employee for one period, with what it was
 * worth and cost then. The grant is the record; delivering it is the
 * host's (in kind) or payroll's (cash).
 */
class BenefitGrant extends Model
{
    protected $table = 'hr_benefit_grants';

    protected $guarded = [];

    protected $casts = [
        'granted_on' => DateOnly::class,
        'amount' => DecimalString::class,
        'employer_cost' => DecimalString::class,
        'status' => BenefitGrantStatus::class,
        'attempts' => 'integer',
        'fulfilled_at' => 'datetime',
    ];

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(Benefit::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(BenefitAssignment::class, 'benefit_assignment_id');
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
