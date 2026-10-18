<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\DecimalString;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pay line of one employee over [effective_from, effective_to]. Pay is
 * never edited: a change closes the line and opens another, so the rows are
 * the pay history.
 */
class Compensation extends Model
{
    protected $table = 'hr_compensations';

    protected $fillable = [
        'employee_id',
        'pay_component_id',
        'amount',
        'currency',
        'effective_from',
        'effective_to',
        'event_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => DecimalString::class,
        'effective_from' => DateOnly::class,
        'effective_to' => DateOnly::class,
    ];

    /**
     * Running at some point of [from, to].
     *
     * @param  Builder<self>  $query
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('effective_from', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->toDateString()));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class, 'pay_component_id');
    }
}
