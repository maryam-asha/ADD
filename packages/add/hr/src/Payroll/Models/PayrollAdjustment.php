<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DecimalString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off amount for one employee in one run (a bonus, a correction).
 */
class PayrollAdjustment extends Model
{
    protected $table = 'hr_payroll_adjustments';

    protected $guarded = [];

    protected $casts = [
        'amount' => DecimalString::class,
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
