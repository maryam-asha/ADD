<?php

namespace App\Domain\Membership\Services;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Exceptions\DeskAssignmentRefusedException;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * The one place a Dedicated Desk subscription is tied to its desks, and the
 * one place that says which subscription holds a desk
 * (docs/decisions/coworking-seat-pool.md).
 *
 * A desk is held by an active subscription between its dates and, when its
 * plan has hours, only inside them — so two subscriptions whose hours never
 * meet may share one. An ended subscription keeps its `membership_seat_desk`
 * row as history and frees the desk.
 * Only staff tie desks, at activation or later. The seat itself is already
 * counted in the branch's pool by the subscription — tying a desk only says
 * which one, so nothing here touches the pool.
 */
class DedicatedDeskService
{
    use LogsSensitiveActions;

    /**
     * Replaces the subscription's desks with $deskIds (none unties them all).
     * Refused when the plan is not a Dedicated Desk plan, when there are more
     * desks than the subscription holds seats, when a desk is not in a
     * co_space of its branch, or when another live subscription holds a desk
     * at some moment this one would (takenDeskIds).
     *
     * Locks the branch row — the one every seat decision locks — so two staff
     * cannot hand the same desk out at once.
     *
     * @param  list<int>  $deskIds
     */
    public function assign(Membership $membership, array $deskIds): void
    {
        $deskIds = array_values(array_unique(array_map('intval', $deskIds)));

        DB::transaction(function () use ($membership, $deskIds) {
            Branch::query()->whereKey($membership->branch_id)->lockForUpdate()->first();

            if ($deskIds !== []) {
                $this->assertAssignable($membership, $deskIds);
            }

            $before = $membership->seatDesks()->pluck('seats_desks.id')->all();
            $membership->seatDesks()->sync($deskIds);

            $this->logSensitiveAction('membership_desks_assigned', $membership, [
                'before' => $before,
                'after' => $deskIds,
            ]);
        });
    }

    /**
     * Adds `membership_id` — the subscription holding the desk now, or null —
     * to a SeatDesk query.
     */
    public function withHolder(Builder $desks): Builder
    {
        return $desks->addSelect([
            'membership_id' => $this->liveHolders()->select('membership_seat_desk.membership_id')->limit(1),
        ]);
    }

    /** Whether a live subscription holds this desk. */
    public function isHeld(int $deskId): bool
    {
        return $this->whereFree(SeatDesk::query()->whereKey($deskId))->doesntExist();
    }

    /** Whether a live subscription holds any desk of this space. */
    public function spaceHasHeldDesks(int $spaceId): bool
    {
        return SeatDesk::query()
            ->where('space_id', $spaceId)
            ->whereExists($this->liveHolders()->select(DB::raw(1)))
            ->exists();
    }

    /** Only desks no subscription holds now. */
    public function whereFree(Builder $desks): Builder
    {
        return $desks->whereNotExists($this->liveHolders()->select(DB::raw(1)));
    }

    /** @param  list<int>  $deskIds */
    private function assertAssignable(Membership $membership, array $deskIds): void
    {
        if (! $membership->plan->isDedicatedDesk()) {
            throw new DeskAssignmentRefusedException('api.membership.desks_need_dedicated_plan');
        }

        if (count($deskIds) > $membership->seats) {
            throw new DeskAssignmentRefusedException('api.membership.desks_exceed_seats');
        }

        $inBranch = SeatDesk::query()
            ->whereKey($deskIds)
            ->whereHas('space', fn (Builder $q) => $q
                ->where('space_type', SpaceType::CoSpace->value)
                ->whereHas('building', fn (Builder $b) => $b->where('branch_id', $membership->branch_id)))
            ->count();

        if ($inBranch !== count($deskIds)) {
            throw new DeskAssignmentRefusedException('api.membership.desks_outside_branch');
        }

        $taken = $this->takenDeskIds(
            $membership->plan,
            $membership->current_period_start,
            $membership->current_period_end,
            $membership->id,
            $deskIds,
        );

        if ($taken !== []) {
            throw new DeskAssignmentRefusedException('api.membership.desk_taken');
        }
    }

    /**
     * The desks — among $deskIds, or any when null — that another live
     * subscription holds at some moment $plan would hold one over
     * [$start, $end). A desk is held only between its subscription's dates
     * and, when its plan has hours, only inside them; so a morning plan and
     * an evening plan share a desk, and a plan with no hours shares it with
     * nobody. The hours rule is Plan::windowSegments(), the one the seat pool
     * uses.
     *
     * @param  list<int>|null  $deskIds
     * @return list<int>
     */
    public function takenDeskIds(
        Plan $plan,
        CarbonInterface $start,
        CarbonInterface $end,
        ?int $exceptMembershipId = null,
        ?array $deskIds = null,
    ): array {
        $holders = Membership::query()
            ->with(['plan', 'seatDesks' => fn ($q) => $q->select('seats_desks.id')])
            ->where('status', MembershipStatus::Active->value)
            ->where('current_period_end', '>=', now())
            ->where('current_period_end', '>', $start)
            ->where('current_period_start', '<', $end)
            ->when($exceptMembershipId !== null, fn (Builder $q) => $q->whereKeyNot($exceptMembershipId))
            ->whereHas('seatDesks', fn (Builder $q) => $q->when(
                $deskIds !== null,
                fn (Builder $d) => $d->whereIn('seats_desks.id', $deskIds),
            ))
            ->get();

        $taken = [];

        foreach ($holders as $holder) {
            $from = max(CarbonImmutable::instance($start), CarbonImmutable::instance($holder->current_period_start));
            $to = min(CarbonImmutable::instance($end), CarbonImmutable::instance($holder->current_period_end));

            if ($this->segmentsMeet($plan->windowSegments($from, $to), $holder->plan->windowSegments($from, $to))) {
                array_push($taken, ...$holder->seatDesks->pluck('id')->all());
            }
        }

        $taken = array_values(array_unique($taken));

        return $deskIds === null ? $taken : array_values(array_intersect($taken, $deskIds));
    }

    /**
     * Whether two chronological lists of [start, end) stretches share any
     * moment — a walk down both at once.
     *
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $a
     * @param  list<array{0: CarbonImmutable, 1: CarbonImmutable}>  $b
     */
    private function segmentsMeet(array $a, array $b): bool
    {
        [$i, $j] = [0, 0];

        while ($i < count($a) && $j < count($b)) {
            if ($a[$i][0]->lessThan($b[$j][1]) && $b[$j][0]->lessThan($a[$i][1])) {
                return true;
            }

            $a[$i][1]->lessThanOrEqualTo($b[$j][1]) ? $i++ : $j++;
        }

        return false;
    }

    /** Rows tying `seats_desks.id` of the outer query to a live subscription. */
    private function liveHolders(): QueryBuilder
    {
        return DB::table('membership_seat_desk')
            ->join('memberships', 'memberships.id', '=', 'membership_seat_desk.membership_id')
            ->whereColumn('membership_seat_desk.seat_desk_id', 'seats_desks.id')
            ->where('memberships.status', MembershipStatus::Active->value)
            ->where('memberships.current_period_end', '>=', now());
    }
}
