<?php

namespace App\Domain\Membership\Models;

use App\Domain\Membership\Concerns\ValidatesOwner;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The actual purchase record (ERD v2.0 naming). Same manual-polymorphic
 * owner_type/owner_id pattern as Wallet, but no uniqueness constraint — an
 * owner may hold more than one concurrent membership.
 */
class Membership extends Model
{
    use HasFactory, ValidatesOwner;

    protected $fillable = [
        'plan_id',
        'owner_type',
        'owner_id',
        'status',
        'current_period_start',
        'current_period_end',
    ];

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'status' => MembershipStatus::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::validateOwnerOnSaving();

        static::saving(function (self $model) {
            $plan = $model->relationLoaded('plan') ? $model->plan : Plan::find($model->plan_id);

            if ($plan !== null && $plan->is_subscription === false) {
                throw new \InvalidArgumentException(
                    'A Membership cannot be created for a one-time package plan (is_subscription=false).'
                );
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
