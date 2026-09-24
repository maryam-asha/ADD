<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Public catalog (two-tier Admin/Public controller, later phase). `price_points`
 * and `overage_rate_points` are whole ADD Points — a cardinal quantity, cast
 * as integers rather than the decimal cast used for the old dollar columns.
 */
class Plan extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'plan_module_id',
        'name',
        'subtitle',
        'is_subscription',
        'price_points',
        'overage_rate_points',
        'duration_days',
        'included_hours',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'subtitle' => 'array',
            'is_subscription' => 'boolean',
            'price_points' => 'integer',
            'included_hours' => 'decimal:2',
            'overage_rate_points' => 'integer',
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

    public function discounts(): HasMany
    {
        return $this->hasMany(PlanDiscount::class)->orderBy('order');
    }
}
