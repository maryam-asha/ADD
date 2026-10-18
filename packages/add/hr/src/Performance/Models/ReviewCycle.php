<?php

namespace Add\Hr\Performance\Models;

use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Performance\Enums\CycleStatus;
use Add\Hr\Support\Casts\DateOnly;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One round of reviews over a period, moved by HR through its phases.
 */
class ReviewCycle extends Model
{
    use HasTranslations;

    protected $table = 'hr_review_cycles';

    protected $fillable = [
        'name',
        'review_template_id',
        'template',
        'period_start',
        'period_end',
        'org_unit_id',
        'status',
        'goals_due_on',
        'self_due_on',
        'manager_due_on',
        'launched_at',
        'closed_at',
        'created_by',
    ];

    protected $casts = [
        'name' => 'array',
        'template' => 'array',
        'period_start' => DateOnly::class,
        'period_end' => DateOnly::class,
        'status' => CycleStatus::class,
        'goals_due_on' => DateOnly::class,
        'self_due_on' => DateOnly::class,
        'manager_due_on' => DateOnly::class,
        'launched_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function reviewTemplate(): BelongsTo
    {
        return $this->belongsTo(ReviewTemplate::class);
    }

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /**
     * @return list<array{code: string, name: array<string, string>, weight: int}>
     */
    public function competencies(): array
    {
        return $this->template['competencies'] ?? [];
    }

    public function scaleMax(): int
    {
        return (int) ($this->template['scale_max'] ?? 5);
    }

    public function goalsWeight(): int
    {
        return (int) ($this->template['goals_weight'] ?? 0);
    }
}
