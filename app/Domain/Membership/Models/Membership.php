<?php

namespace App\Domain\Membership\Models;

use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Membership\Concerns\ValidatesOwner;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Services\MembershipBranchResolver;
use App\Domain\Renewals\Enums\RenewalStopReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The actual purchase record (ERD v2.0 naming). Same manual-polymorphic
 * owner_type/owner_id pattern as Wallet, but no uniqueness constraint — an
 * owner may hold more than one concurrent membership.
 *
 * It holds `seats` seats in its branch's co-working pool for as long as it
 * is active, and only while its plan is open when the plan has an access
 * window (docs/decisions/coworking-seat-pool.md). The branch, when not
 * given, comes from MembershipBranchResolver on save.
 */
class Membership extends Model
{
    use HasFactory, ValidatesOwner;

    protected $fillable = [
        'plan_id',
        'owner_type',
        'owner_id',
        'branch_id',
        'seats',
        'status',
        'current_period_start',
        'current_period_end',
        'auto_renew',
        'renewed_from_id',
        'renewal_stop_reason',
        'balances_granted_at',
    ];

    protected function casts(): array
    {
        return [
            'owner_type' => OwnerType::class,
            'status' => MembershipStatus::class,
            'seats' => 'integer',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'auto_renew' => 'boolean',
            'renewal_stop_reason' => RenewalStopReason::class,
            'balances_granted_at' => 'datetime',
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

            if ($model->branch_id === null && $model->owner_type !== null && $model->owner_id !== null) {
                $model->branch_id = app(MembershipBranchResolver::class)->branchFor($model->owner_type, (int) $model->owner_id);
            }
        });
    }

    /** The subscription this one renews. */
    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    /** The subscription that renews this one, once created. */
    public function renewal(): HasOne
    {
        return $this->hasOne(self::class, 'renewed_from_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The desks a Dedicated Desk subscription is tied to — tied and untied
     * only through DedicatedDeskService.
     */
    public function seatDesks(): BelongsToMany
    {
        return $this->belongsToMany(SeatDesk::class, 'membership_seat_desk')->withTimestamps();
    }

    /**
     * Started, active and not yet ended. Nothing writes an `expired` status:
     * a subscription past `current_period_end` is over by its date alone, and
     * a renewal made in advance is not live until its period starts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', MembershipStatus::Active)
            ->where('current_period_start', '<=', now())
            ->where('current_period_end', '>=', now());
    }

    /**
     * Over: past its period, or no longer active — what scopeLive() is not.
     *
     * @param  Builder<self>  $query
     */
    public function scopeEnded(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('status', '!=', MembershipStatus::Active)
            ->orWhere('current_period_end', '<', now()));
    }

    /** The same rule as scopeLive(), for a loaded row. */
    public function isLive(): bool
    {
        return $this->status === MembershipStatus::Active
            && ! $this->current_period_start->isFuture()
            && ! $this->current_period_end->isPast();
    }
}
