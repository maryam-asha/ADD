<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The structured, computable sibling of PlanFeature's plain-text discount
 * sentences (docs/decisions/plan-discounts-design.md). `space_id` and
 * `space_type` are mutually exclusive — a specific space already implies
 * its type — enforced below.
 */
class PlanDiscount extends Model
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'plan_id',
        'percentage',
        'space_id',
        'space_type',
        'usage_limit',
        'description',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'integer',
            'space_type' => SpaceType::class,
            'usage_limit' => 'integer',
            'description' => 'array',
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    /**
     * Mirrors WalletTransaction::booted()'s restricted_space_id/category
     * guard: a discount may target one specific space, one whole space
     * type, or neither (plan-wide) — never a space and a type at once.
     */
    protected static function booted(): void
    {
        static::saving(function (self $discount) {
            if ($discount->space_id !== null && $discount->space_type !== null) {
                throw new \InvalidArgumentException(
                    'A PlanDiscount may target space_id or space_type, not both.'
                );
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'space_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PlanDiscountRedemption::class);
    }
}
