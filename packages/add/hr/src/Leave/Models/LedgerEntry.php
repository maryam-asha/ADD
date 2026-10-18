<?php

namespace Add\Hr\Leave\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Leave\Enums\LedgerKind;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\PlainDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One movement of a leave balance. Rows are never edited or deleted: a
 * correction is another movement. The balance is their sum.
 */
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'hr_leave_ledger';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'kind',
        'amount',
        'effective_on',
        'period_year',
        'leave_request_id',
        'reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'kind' => LedgerKind::class,
        'amount' => PlainDecimal::class,
        'effective_on' => DateOnly::class,
        'period_year' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }
}
