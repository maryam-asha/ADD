<?php

namespace Add\Hr\Leave\Models;

use Add\Hr\Core\Models\OrgUnit;
use Add\Hr\Core\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A minimum headcount at work, per unit or per position, that approving a
 * leave may not break.
 */
class CoverageRule extends Model
{
    protected $table = 'hr_coverage_rules';

    protected $guarded = [];

    protected $casts = [
        'min_present' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function orgUnit(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }
}
