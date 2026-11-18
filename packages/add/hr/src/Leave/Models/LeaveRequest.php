<?php

namespace Add\Hr\Leave\Models;

use Add\Hr\Approvals\Models\ApprovalRequest;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\EmployeeDocument;
use Add\Hr\Leave\Enums\HalfDay;
use Add\Hr\Leave\Enums\LeaveRequestStatus;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\PlainDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One leave asked for. Created and changed only by LeaveService and its
 * approval handler.
 */
class LeaveRequest extends Model
{
    protected $table = 'hr_leave_requests';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'starts_on',
        'ends_on',
        'half_day',
        'hours',
        'amount',
        'reason',
        'document_id',
        'status',
        'approval_request_id',
        'submitted_by',
        'cancelled_at',
        'cancelled_by',
    ];

    protected $casts = [
        'starts_on' => DateOnly::class,
        'ends_on' => DateOnly::class,
        'half_day' => HalfDay::class,
        'hours' => PlainDecimal::class,
        'amount' => PlainDecimal::class,
        'status' => LeaveRequestStatus::class,
        'cancelled_at' => 'datetime',
    ];

    /**
     * Pending or approved: the requests that hold their days.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereIn('status', [LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value]);
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString());
    }

    /**
     * Away the whole day: not a half day and not a few hours.
     */
    public function isFullDay(): bool
    {
        return $this->half_day === null && $this->hours === null;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class, 'document_id');
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
