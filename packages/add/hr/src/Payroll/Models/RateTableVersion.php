<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\DecimalString;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The rate or brackets in force over [effective_from, effective_to].
 */
class RateTableVersion extends Model
{
    protected $table = 'hr_rate_table_versions';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => DateOnly::class,
        'effective_to' => DateOnly::class,
        'rate' => DecimalString::class,
        'max_base' => DecimalString::class,
        'brackets' => 'array',
    ];

    public function rateTable(): BelongsTo
    {
        return $this->belongsTo(RateTable::class);
    }
}
