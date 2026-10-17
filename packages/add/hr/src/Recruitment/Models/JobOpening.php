<?php

namespace Add\Hr\Recruitment\Models;

use Add\Hr\Core\Enums\EmploymentType;
use Add\Hr\Core\Models\WorkLocation;
use Add\Hr\Recruitment\Enums\OpeningStatus;
use Add\Hr\Recruitment\Enums\OpeningVisibility;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Concerns\HasTranslations;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A job advertised for an approved requisition, publicly or to employees
 * only.
 */
class JobOpening extends Model
{
    use HasTranslations;

    protected $table = 'hr_job_openings';

    protected $guarded = [];

    protected $casts = [
        'title' => 'array',
        'description' => 'array',
        'employment_type' => EmploymentType::class,
        'visibility' => OpeningVisibility::class,
        'status' => OpeningStatus::class,
        'closes_on' => DateOnly::class,
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Taking applications on that day.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAccepting(Builder $query, CarbonInterface $on): void
    {
        $query->where('status', OpeningStatus::Open->value)
            ->where(fn ($q) => $q->whereNull('closes_on')->orWhere('closes_on', '>=', $on->toDateString()));
    }

    public function isAccepting(CarbonInterface $on): bool
    {
        return $this->status === OpeningStatus::Open
            && ($this->closes_on === null || $this->closes_on->toDateString() >= $on->toDateString());
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function workLocation(): BelongsTo
    {
        return $this->belongsTo(WorkLocation::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
