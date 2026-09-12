<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Public catalog (two-tier Admin/Public controller, later phase). `price`
 * and `overage_rate` use the same `decimal:2` cast as the one existing
 * money column in this codebase (`Space::hourly_rate`) — decision #15's
 * DECIMAL-only rule applies to the cast too, not just the migration column.
 */
class Plan extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'plan_module_id',
        'name',
        'subtitle',
        'is_subscription',
        'price',
        'pricing_currency',
        'duration_days',
        'included_hours',
        'overage_rate',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'subtitle' => 'array',
            'is_subscription' => 'boolean',
            'price' => 'decimal:2',
            'included_hours' => 'decimal:2',
            'overage_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function planModule(): BelongsTo
    {
        return $this->belongsTo(PlanModule::class, 'plan_module_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(PlanBalance::class)->orderBy('order');
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class)->orderBy('order');
    }
}
