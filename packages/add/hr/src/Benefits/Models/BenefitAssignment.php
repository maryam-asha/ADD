<?php

namespace Add\Hr\Benefits\Models;

use Add\Hr\Core\Models\Employee;
use Add\Hr\Core\Models\JobGrade;
use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Casts\DecimalString;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A benefit given, over [effective_from, effective_to], to one employee, a
 * grade, or a unit and its sub-units. Never edited, only ended.
 */
class BenefitAssignment extends Model
{
    protected $table = 'hr_benefit_assignments';

    protected $fillable = [
        'benefit_id',
        'employee_id',
        'job_grade_id',
        'org_unit_id',
        'amount',
        'employer_cost',
        'excluded',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    protected $casts = [
        'amount' => DecimalString::class,
        'employer_cost' => DecimalString::class,
        'excluded' => 'boolean',
        'effective_from' => DateOnly::class,
        'effective_to' => DateOnly::class,
    ];

    protected $attributes = [
        'excluded' => false,
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

    public function target(): string
    {
        return match (true) {
            $this->employee_id !== null => 'employee',
            $this->job_grade_id !== null => 'grade',
            default => 'org_unit',
        };
    }

    public function benefit(): BelongsTo
    {
        return $this->belongsTo(Benefit::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }
}
