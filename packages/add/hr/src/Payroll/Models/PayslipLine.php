<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Support\Casts\DecimalString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot line: the component's code and name as they were.
 */
class PayslipLine extends Model
{
    protected $table = 'hr_payslip_lines';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'kind' => PayComponentKind::class,
        'quantity' => DecimalString::class,
        'rate' => DecimalString::class,
        'amount' => DecimalString::class,
        'original_amount' => DecimalString::class,
        'taxable' => 'boolean',
        'insurable' => 'boolean',
        'sort' => 'integer',
    ];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
