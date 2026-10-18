<?php

namespace Add\Hr\Benefits\Models;

use Add\Hr\Benefits\Enums\BenefitFrequency;
use Add\Hr\Benefits\Enums\BenefitKind;
use Add\Hr\Payroll\Models\PayComponent;
use Add\Hr\Support\Casts\DecimalString;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A benefit and what one grant of it is worth. Who receives it is its
 * assignments; what each employee received is its grants.
 */
class Benefit extends Model
{
    use HasTranslations;

    protected $table = 'hr_benefits';

    protected $fillable = [
        'code',
        'name',
        'description',
        'kind',
        'frequency',
        'grant_month',
        'amount',
        'currency',
        'unit',
        'employer_cost',
        'cost_currency',
        'pay_component_id',
        'fulfillment',
        'eligible_after_days',
        'is_active',
    ];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'kind' => BenefitKind::class,
        'frequency' => BenefitFrequency::class,
        'grant_month' => 'integer',
        'amount' => DecimalString::class,
        'employer_cost' => DecimalString::class,
        'fulfillment' => 'array',
        'eligible_after_days' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'eligible_after_days' => 0,
        'is_active' => true,
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(BenefitAssignment::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(BenefitGrant::class);
    }

    public function payComponent(): BelongsTo
    {
        return $this->belongsTo(PayComponent::class);
    }
}
