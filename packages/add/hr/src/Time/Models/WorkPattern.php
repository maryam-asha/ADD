<?php

namespace Add\Hr\Time\Models;

use Add\Hr\Support\Concerns\HasTranslations;
use Add\Hr\Time\Enums\WorkPatternKind;
use Illuminate\Database\Eloquent\Model;

/**
 * How an employee's week is planned; set on their assignment.
 */
class WorkPattern extends Model
{
    use HasTranslations;

    protected $table = 'hr_work_patterns';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'kind' => WorkPatternKind::class,
        'weekly' => 'array',
        'weekly_hours' => 'float',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * The shift a fixed pattern plans on an ISO weekday (1–7), or null for
     * a rest day.
     */
    public function shiftIdFor(int $isoWeekday): ?int
    {
        $id = ($this->weekly ?? [])[(string) $isoWeekday] ?? null;

        return $id === null ? null : (int) $id;
    }
}
