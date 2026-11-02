<?php

namespace App\Domain\Membership\Models;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Plan hours one person spent, on the walk-in or booking that spent them,
 * counted against the cycle it started in. `plan_space_hour_id` null means
 * the plan's own coworking hours. Written and released only through
 * PlanAllowanceService.
 */
class PlanHourUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'membership_id',
        'user_id',
        'plan_space_hour_id',
        'usable_type',
        'usable_id',
        'cycle_start',
        'seconds',
    ];

    protected function casts(): array
    {
        return [
            'cycle_start' => 'datetime',
            'seconds' => 'integer',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The space-hours benefit spent from, or null for the plan's coworking hours. */
    public function allowance(): BelongsTo
    {
        return $this->belongsTo(PlanSpaceHour::class, 'plan_space_hour_id');
    }

    public function usable(): MorphTo
    {
        return $this->morphTo();
    }
}
