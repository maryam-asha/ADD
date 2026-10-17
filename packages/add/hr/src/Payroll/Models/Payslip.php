<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DecimalString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's pay in one run, with every line as it was computed: the
 * slip never changes if rules or pay change later.
 */
class Payslip extends Model
{
    protected $table = 'hr_payslips';

    protected $guarded = [];

    protected $casts = [
        'gross' => DecimalString::class,
        'deductions' => DecimalString::class,
        'net' => DecimalString::class,
        'employer_cost' => DecimalString::class,
        'employed_days' => 'integer',
        'days_in_month' => 'integer',
        'snapshot' => 'array',
        'warnings' => 'array',
        'published_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort')->orderBy('id');
    }
}
