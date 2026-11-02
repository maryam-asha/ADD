<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Database\Factories\PositionFactory;
use Add\Hr\Support\Concerns\HasTranslations;
use Add\Hr\Support\LocalDay;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A seat in a unit: `headcount` employees may hold it at once.
 */
class Position extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'hr_positions';

    protected $fillable = [
        'org_unit_id',
        'job_grade_id',
        'code',
        'title',
        'headcount',
        'is_active',
    ];

    protected $casts = [
        'title' => 'array',
        'headcount' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'headcount' => 1,
        'is_active' => true,
    ];

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * Seats taken from that day on — by holders today and by anyone already
     * assigned to start later. A seat is a person, not an assignment: a
     * holder with a later change that keeps them here (a scheduled
     * promotion) has two assignments open and takes one seat. The same
     * count hiring checks against, leaving out the employee being moved.
     */
    public function occupiedCount(?CarbonInterface $on = null, int|string|null $exceptEmployeeId = null): int
    {
        return $this->assignments()->openFrom($on ?? LocalDay::today())
            ->when($exceptEmployeeId !== null, fn ($q) => $q->where('employee_id', '!=', $exceptEmployeeId))
            ->distinct()
            ->count('employee_id');
    }

    /**
     * occupiedCount() as an `occupied` column, for a list of positions.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithOccupied(Builder $query, CarbonInterface $on): void
    {
        $query->withAggregate(['assignments as occupied' => fn ($q) => $q->openFrom($on)], DB::raw('distinct employee_id'), 'count');
    }

    protected static function newFactory(): PositionFactory
    {
        return PositionFactory::new();
    }
}
