<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Pricing\Enums\CouponRevisionAction;
use App\Domain\Pricing\Enums\DiscountKind;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Models\CouponRevision;
use App\Domain\Pricing\Models\CouponTarget;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which offers ran when. Each time an admin creates, changes or deletes an
 * offer or a coupon, a copy of it is kept (record()); a period is then
 * asked what was running in it (running()): every stretch in which it was
 * switched on and inside its own dates, on the terms it had then, and what
 * it gave in the period — read from the ledger, which keeps each use's own
 * copy of the terms.
 */
class CouponHistory
{
    /** What a stretch is priced on; a change to anything else (a name) does not split it. */
    private const TERMS = ['discount_type', 'discount_value', 'max_discount_points', 'min_amount_points', 'targets'];

    public function record(Coupon $coupon, CouponRevisionAction $action, ?User $by): CouponRevision
    {
        // As stored: a freshly created model lacks the columns left to their defaults.
        $coupon = $coupon->fresh(['targets', 'users']) ?? $coupon;

        return CouponRevision::create([
            'coupon_id' => $coupon->id,
            'action' => $action,
            'is_automatic' => (bool) $coupon->is_automatic,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'discount_type' => $coupon->discount_type,
            'discount_value' => $coupon->discount_value,
            'max_discount_points' => $coupon->max_discount_points,
            'min_amount_points' => $coupon->min_amount_points,
            'combinable_with_offers' => (bool) $coupon->combinable_with_offers,
            'usage_limit' => $coupon->usage_limit,
            'per_user_limit' => $coupon->per_user_limit,
            'new_members_only' => (bool) $coupon->new_members_only,
            'company_id' => $coupon->company_id,
            'targets' => $coupon->targets->sortBy('id')->map(fn (CouponTarget $target) => [
                'purpose' => $target->purpose->value,
                'plan_id' => $target->plan_id,
                'space_id' => $target->space_id,
                'space_type' => $target->space_type?->value,
                'event_id' => $target->event_id,
            ])->values()->all(),
            'user_ids' => $coupon->users->pluck('id')->sort()->values()->all(),
            'starts_at' => $coupon->starts_at,
            'ends_at' => $coupon->ends_at,
            'is_active' => (bool) $coupon->is_active,
            'changed_by' => $by?->id,
            'recorded_at' => now(),
        ]);
    }

    /**
     * The offers (or coupons) that ran in [`$from`, `$to`), soonest first.
     *
     * @return list<array<string, mixed>>
     */
    public function running(CarbonInterface $from, CarbonInterface $to, bool $automatic): array
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        $revisions = CouponRevision::query()
            ->where('is_automatic', $automatic)
            ->whereIn('coupon_id', CouponRevision::query()->select('coupon_id')->where('is_automatic', $automatic)->where('recorded_at', '<', $to))
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get()
            ->groupBy('coupon_id');

        $given = AppliedDiscount::query()
            ->live()
            ->whereIn('kind', [DiscountKind::Offer, DiscountKind::Coupon])
            ->whereIn('coupon_id', $revisions->keys())
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $to)
            ->selectRaw('coupon_id, count(*) as operations, sum(points) as points')
            ->groupBy('coupon_id')
            ->get()
            ->keyBy('coupon_id');

        return $revisions
            ->map(function (Collection $history, int $couponId) use ($from, $to, $given) {
                $periods = $this->periods($history->values(), $from, $to);

                if ($periods === []) {
                    return null;
                }

                /** @var CouponRevision $latest */
                $latest = $history->last();

                return [
                    'id' => $couponId,
                    'name' => $latest->translate('name'),
                    'code' => $latest->code,
                    'deleted' => $latest->action === CouponRevisionAction::Deleted,
                    'periods' => $periods,
                    'operations' => (int) ($given->get($couponId)?->operations ?? 0),
                    'points' => (int) ($given->get($couponId)?->points ?? 0),
                ];
            })
            ->filter()
            ->sortBy(fn (array $offer) => [$offer['periods'][0]['from'], $offer['id']])
            ->values()
            ->all();
    }

    /**
     * Each revision holds from its own `recorded_at` until the next one; it
     * ran in that stretch while switched on and inside its own dates.
     *
     * @param  Collection<int, CouponRevision>  $history  oldest first
     * @return list<array<string, mixed>>
     */
    private function periods(Collection $history, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $periods = [];

        foreach ($history as $i => $revision) {
            if ($revision->action === CouponRevisionAction::Deleted || ! $revision->is_active) {
                continue;
            }

            $next = $history->get($i + 1)?->recorded_at;
            $start = $this->latest($from, $revision->recorded_at, $revision->starts_at);
            $end = $this->earliest($to, $next, $revision->ends_at);

            if ($start->greaterThanOrEqualTo($end)) {
                continue;
            }

            $terms = $revision->only(self::TERMS);
            $terms['discount_type'] = $revision->discount_type->value;
            $last = array_key_last($periods);

            // A change that kept the terms (a new name) continues the stretch.
            if ($last !== null && $periods[$last]['to'] === $start->toIso8601String() && array_intersect_key($periods[$last], $terms) == $terms) {
                $periods[$last]['to'] = $end->toIso8601String();

                continue;
            }

            $periods[] = ['from' => $start->toIso8601String(), 'to' => $end->toIso8601String()] + $terms;
        }

        return $periods;
    }

    private function latest(?CarbonInterface ...$instants): CarbonImmutable
    {
        return CarbonImmutable::instance(max(array_filter($instants)))->utc();
    }

    private function earliest(?CarbonInterface ...$instants): CarbonImmutable
    {
        return CarbonImmutable::instance(min(array_filter($instants)))->utc();
    }
}
