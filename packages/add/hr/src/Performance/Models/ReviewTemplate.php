<?php

namespace Add\Hr\Performance\Models;

use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a review rates: competencies (each with a weight) on a 1..scale_max
 * scale, plus the employee's goals for goals_weight percent of the score.
 */
class ReviewTemplate extends Model
{
    use HasTranslations;

    protected $table = 'hr_review_templates';

    protected $fillable = [
        'name',
        'scale_max',
        'competencies',
        'goals_weight',
        'is_active',
    ];

    protected $casts = [
        'name' => 'array',
        'scale_max' => 'integer',
        'competencies' => 'array',
        'goals_weight' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function cycles(): HasMany
    {
        return $this->hasMany(ReviewCycle::class);
    }

    /**
     * The part reviews read, frozen into a cycle at launch.
     *
     * @return array{scale_max: int, goals_weight: int, competencies: list<array<string, mixed>>}
     */
    public function snapshot(): array
    {
        return [
            'scale_max' => $this->scale_max,
            'goals_weight' => $this->goals_weight,
            'competencies' => array_values($this->competencies),
        ];
    }
}
