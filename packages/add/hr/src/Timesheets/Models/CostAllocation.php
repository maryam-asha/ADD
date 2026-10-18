<?php

namespace Add\Hr\Timesheets\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\PlainDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an employee's planned cost split over [effective_from,
 * effective_to]: the share of their cost a cost center carries when no
 * approved timesheet says otherwise.
 */
class CostAllocation extends Model
{
    protected $table = 'hr_cost_allocations';

    protected $fillable = [
        'employee_id',
        'cost_center_id',
        'percent',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'percent' => PlainDecimal::class,
        'effective_from' => DateOnly::class,
        'effective_to' => DateOnly::class,
    ];

    /**
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonInterface $on): void
    {
        $day = $on->toDateString();

        $query->where('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
