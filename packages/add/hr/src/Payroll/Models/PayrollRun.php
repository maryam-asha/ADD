<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Payroll\Enums\PayrollRunKind;
use Add\Hr\Payroll\Enums\PayrollRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One payroll for one month. Changed only by PayrollRunService.
 */
class PayrollRun extends Model
{
    protected $table = 'hr_payroll_runs';

    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'kind' => PayrollRunKind::class,
        'status' => PayrollRunStatus::class,
        'employee_ids' => 'array',
        'exchange_rates' => 'array',
        'parallel_run' => 'boolean',
        'reconciled_at' => 'datetime',
        'calculated_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function monthStart(): CarbonImmutable
    {
        return CarbonImmutable::create($this->year, $this->month, 1);
    }

    public function monthEnd(): CarbonImmutable
    {
        return $this->monthStart()->endOfMonth()->startOfDay();
    }

    public function isLocked(): bool
    {
        return in_array($this->status, [PayrollRunStatus::Approved, PayrollRunStatus::Paid], true);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }
}
