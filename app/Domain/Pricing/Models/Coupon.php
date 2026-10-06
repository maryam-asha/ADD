<?php

namespace App\Domain\Pricing\Models;

use App\Concerns\HasTranslations;
use App\Domain\Identity\Models\Company;
use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponDiscountType;
use App\Domain\Pricing\Enums\CouponSource;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A code a member types for a discount
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md) —
 * or, `is_automatic`, an offer: no code, it applies itself wherever it is
 * the best for the member
 * (docs/superpowers/specs/2026-10-05-automatic-offers-savings-discount-ledger-design.md).
 *
 * What it is for is `targets` (none: everything a coupon can be spent on);
 * who may use it is the rest — named members (`users`), a company's
 * members, new members only — composed freely. Its limits are counted from
 * `redemptions`, never stored.
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory, HasTranslations;

    protected $fillable = [
        'code',
        'is_automatic',
        'name',
        'description',
        'source',
        'discount_type',
        'discount_value',
        'max_discount_points',
        'min_amount_points',
        'combinable_with_offers',
        'usage_limit',
        'per_user_limit',
        'new_members_only',
        'company_id',
        'starts_at',
        'ends_at',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_automatic' => 'boolean',
            'name' => 'array',
            'description' => 'array',
            'source' => CouponSource::class,
            'discount_type' => CouponDiscountType::class,
            'discount_value' => 'integer',
            'max_discount_points' => 'integer',
            'min_amount_points' => 'integer',
            'combinable_with_offers' => 'boolean',
            'usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'new_members_only' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A discount of nothing, or of more than the whole price, is refused at
     * the model too — the same backstop PlanDiscount::booted() keeps.
     */
    protected static function booted(): void
    {
        static::saving(function (self $coupon) {
            $value = (int) $coupon->discount_value;

            if ($value < 1 || ($coupon->discount_type === CouponDiscountType::Percentage && $value > 100)) {
                throw new InvalidArgumentException('A coupon takes off between 1 and 100 percent, or at least one point.');
            }

            if ($coupon->discount_type === CouponDiscountType::FixedPrice && ! $coupon->is_automatic) {
                throw new InvalidArgumentException('A fixed price is for automatic offers only.');
            }
        });
    }

    /** Codes are matched case-blind: stored trimmed and upper-cased. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** An offer has no code. */
    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['code'] = $value === null ? null : self::normalizeCode($value);
    }

    public function scopeAutomatic(Builder $query): Builder
    {
        return $query->where('is_automatic', true);
    }

    public function appliesToEverything(): bool
    {
        return ! ($this->relationLoaded('targets') ? $this->targets->isNotEmpty() : $this->targets()->exists());
    }

    public function targets(): HasMany
    {
        return $this->hasMany(CouponTarget::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'coupon_user')->withTimestamps();
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    protected static function newFactory(): CouponFactory
    {
        return CouponFactory::new();
    }
}
