<?php

namespace Add\Hr\Timesheets\Models;

use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Where staff time and cost are charged: a department budget, a project,
 * a grant.
 */
class CostCenter extends Model
{
    use HasTranslations;

    protected $table = 'hr_cost_centers';

    protected $fillable = [
        'code',
        'name',
        'costable_type',
        'costable_id',
        'is_active',
    ];

    protected $casts = [
        'name' => 'array',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    public function costable(): MorphTo
    {
        return $this->morphTo();
    }
}
