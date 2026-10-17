<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Database\Factories\PositionFactory;
use Add\Hr\Support\Concerns\HasTranslations;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A seat in a unit: `headcount` employees may hold it at once.
 */
class Position extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'hr_positions';

    protected $guarded = [];

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
     * assigned to start later. The same count hiring checks against.
     */
    public function occupiedCount(?CarbonInterface $on = null): int
    {
        return $this->assignments()->openFrom($on ?? CarbonImmutable::today())->count();
    }

    protected static function newFactory(): PositionFactory
    {
        return PositionFactory::new();
    }
}
