<?php

namespace App\Domain\Pricing\Models;

use App\Concerns\HasTranslations;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\CouponRevisionAction;
use Illuminate\Database\Eloquent\Model;

/**
 * An offer or a coupon as it stood from `recorded_at` until its next
 * revision — written by CouponHistory only, never changed. Its terms, its
 * dates, whether it was switched on, what it was for and who it was for.
 */
class CouponRevision extends Model
{
    use HasTranslations;

    protected $fillable = [
        'coupon_id',
        'action',
        'is_automatic',
        'code',
        'name',
        'discount_type',
        'discount_value',
        'max_discount_points',
        'min_amount_points',
        'combinable_with_offers',
        'usage_limit',
        'per_user_limit',
        'new_members_only',
        'company_id',
        'targets',
        'user_ids',
        'starts_at',
        'ends_at',
        'is_active',
        'changed_by',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'action' => CouponRevisionAction::class,
            'is_automatic' => 'boolean',
            'name' => 'array',
            'discount_type' => CouponDiscountType::class,
            'discount_value' => 'integer',
            'max_discount_points' => 'integer',
            'min_amount_points' => 'integer',
            'combinable_with_offers' => 'boolean',
            'usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'new_members_only' => 'boolean',
            'company_id' => 'integer',
            'targets' => 'array',
            'user_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }
}
