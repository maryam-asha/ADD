<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Core\Enums\EmploymentType;
use Add\Hr\Core\Enums\WorkMode;
use Add\Hr\Support\Casts\DateOnly;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What an employee does, where and under whom, over [effective_from,
 * effective_to]. A change never edits a row: the current one is closed and
 * a new one opened, so the rows are the job history.
 */
class Assignment extends Model
{
    protected $table = 'hr_assignments';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => DateOnly::class,
        'effective_to' => DateOnly::class,
        'job_title' => 'array',
        'employment_type' => EmploymentType::class,
        'work_mode' => WorkMode::class,
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

    /**
     * Still running on or after that day — what holds a position's seat from
     * then on.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpenFrom(Builder $query, CarbonInterface $on): void
    {
        $query->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $on->toDateString()));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    /**
     * The assignment's own title, else its position's.
     *
     * @return array<string, string>|null
     */
    public function resolvedTitle(): ?array
    {
        return $this->job_title ?? $this->position?->title;
    }
}
