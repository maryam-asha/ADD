<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SpaceCoworkingWindow;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\ValueObjects\PoolDemand;
use App\Domain\Booking\ValueObjects\PoolTimeline;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A branch's co-working seat pool, read from the database into a
 * PoolTimeline (docs/decisions/coworking-seat-pool.md).
 *
 * Seats open: every active co_space in the branch, `capacity` each (none
 * when unset), plus every room lent to co-working for as long as its window
 * lasts. Seats taken: every active subscription in the branch holds its
 * `seats` while its plan is open; every blocking booking on a co_space takes
 * one, on its member's subscription if they hold one here; every open
 * walk-in takes one now — and only now: nobody knows when they will leave.
 *
 * A handful of queries per call, whatever the range; the arithmetic is
 * PoolTimeline's.
 */
class CoworkingPoolService
{
    public function timeline(Branch $branch, CarbonInterface $from, CarbonInterface $to, ?PoolDemand $candidate = null): PoolTimeline
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        $coSpaces = $this->coSpaces($branch);
        $windows = SpaceCoworkingWindow::query()
            ->overlapping($from, $to)
            ->whereHas('space', fn (Builder $space) => $this->inBranch($space, $branch))
            ->get();

        $supply = [
            ...$coSpaces->map(fn (Space $space) => ['start' => $from, 'end' => $to, 'seats' => (int) ($space->capacity ?? 0)]),
            ...$windows->map(fn (SpaceCoworkingWindow $window) => ['start' => $window->starts_at, 'end' => $window->ends_at, 'seats' => $window->seats]),
        ];

        $memberships = $this->heldMemberships($branch, $from, $to);
        $holds = [];

        foreach ($memberships as $membership) {
            foreach ($this->heldStretches($membership, $from, $to) as [$start, $end]) {
                $holds[] = ['membership' => $membership->id, 'start' => $start, 'end' => $end, 'seats' => $membership->seats];
            }
        }

        if ($candidate?->isSubscription()) {
            foreach ($candidate->plan->windowSegments(max($from, $candidate->start), min($to, $candidate->end)) as [$start, $end]) {
                $holds[] = ['membership' => 'candidate', 'start' => $start, 'end' => $end, 'seats' => $candidate->seats];
            }
        }

        $bookings = Booking::query()
            ->blocking()
            ->overlapping($from, $to)
            ->whereIn('space_id', $coSpaces->modelKeys())
            ->when($candidate?->ignoreBookingId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->get(['id', 'user_id', 'start_at', 'end_at']);

        $walkIns = $this->openWalkIns($coSpaces, $windows, $from, $to);

        $userIds = [...$bookings->pluck('user_id'), ...$walkIns->pluck('user_id')];

        if ($candidate?->user !== null) {
            $userIds[] = $candidate->user->id;
        }

        $coveringOf = $this->coveringMemberships($memberships, array_values(array_unique($userIds)));
        $held = $memberships->keyBy('id');

        $presences = $bookings->map(fn (Booking $booking) => [
            'start' => $booking->start_at,
            'end' => $booking->end_at,
            'membership' => $coveringOf[$booking->user_id] ?? null,
        ])->all();

        $now = CarbonImmutable::now()->utc();

        foreach ($walkIns as $walkIn) {
            $presences[] = [
                'start' => $now,
                'end' => $now->addSecond(),
                // The membership stamped at check-in, when it holds seats here.
                'membership' => $held->has($walkIn->membership_id)
                    ? $walkIn->membership_id
                    : ($coveringOf[$walkIn->user_id] ?? null),
            ];
        }

        if ($candidate !== null && ! $candidate->isSubscription()) {
            $presences[] = [
                'start' => $candidate->start,
                'end' => $candidate->end,
                'membership' => $candidate->user !== null ? ($coveringOf[$candidate->user->id] ?? null) : null,
            ];
        }

        return PoolTimeline::build($from, $to, $supply, $holds, $presences);
    }

    /** The fewest free seats in [$from, $to); negative when overbooked. */
    public function freeSeats(Branch $branch, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->timeline($branch, $from, $to)->minFree();
    }

    /**
     * The stretches of [$from, $to) a membership holds its seats: its period,
     * and within it only while its plan is open.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function heldStretches(Membership $membership, CarbonInterface $from, CarbonInterface $to): array
    {
        return $membership->plan->windowSegments(
            max(CarbonImmutable::instance($from)->utc(), CarbonImmutable::instance($membership->current_period_start)->utc()),
            min(CarbonImmutable::instance($to)->utc(), CarbonImmutable::instance($membership->current_period_end)->utc()),
        );
    }

    /**
     * Which of the branch's subscriptions covers each member: their own
     * first, else their company's — the order MembershipResolver uses, but
     * among the subscriptions held in this branch only.
     *
     * @param  Collection<int, Membership>  $memberships
     * @param  list<int>  $userIds
     * @return array<int, int> user id => membership id
     */
    public function coveringMemberships(Collection $memberships, array $userIds): array
    {
        if ($userIds === [] || $memberships->isEmpty()) {
            return [];
        }

        $personal = $memberships
            ->where('owner_type', OwnerType::User)
            ->sortBy('id')
            ->mapWithKeys(fn (Membership $membership) => [$membership->owner_id => $membership->id])
            ->all();

        $byCompany = $memberships
            ->where('owner_type', OwnerType::Company)
            ->sortBy('id')
            ->mapWithKeys(fn (Membership $membership) => [$membership->owner_id => $membership->id])
            ->all();

        $covering = [];

        if ($byCompany !== []) {
            $rows = DB::table('company_user')
                ->whereIn('user_id', $userIds)
                ->whereIn('company_id', array_keys($byCompany))
                ->orderBy('company_id')
                ->get(['user_id', 'company_id']);

            foreach ($rows as $row) {
                $covering[$row->user_id] ??= $byCompany[$row->company_id];
            }
        }

        foreach ($userIds as $userId) {
            if (isset($personal[$userId])) {
                $covering[$userId] = $personal[$userId];
            }
        }

        return $covering;
    }

    /**
     * The branch's subscriptions whose period touches [$from, $to) and has
     * not ended — there is no expiry job, so an ended period is filtered here.
     *
     * @return Collection<int, Membership>
     */
    public function heldMemberships(Branch $branch, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Membership::query()
            ->with('plan')
            ->where('branch_id', $branch->id)
            ->where('status', MembershipStatus::Active)
            ->where('current_period_start', '<', $to)
            ->where('current_period_end', '>', $from)
            ->where('current_period_end', '>=', now())
            ->get();
    }

    /**
     * @return Collection<int, Space>
     */
    private function coSpaces(Branch $branch): Collection
    {
        return Space::query()
            ->where('space_type', SpaceType::CoSpace)
            ->where('status', OperationalStatus::Active)
            ->where(fn (Builder $space) => $this->inBranch($space, $branch))
            ->get(['id', 'capacity']);
    }

    /**
     * Open walk-ins on the pool's spaces — the co_spaces, and the rooms lent
     * to co-working right now — when now lies in the range.
     *
     * @param  Collection<int, Space>  $coSpaces
     * @param  Collection<int, SpaceCoworkingWindow>  $windows
     * @return Collection<int, WalkinSession>
     */
    private function openWalkIns(Collection $coSpaces, Collection $windows, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $now = now();

        if ($now->lessThan($from) || $now->greaterThanOrEqualTo($to)) {
            return new Collection;
        }

        $lentNow = $windows
            ->filter(fn (SpaceCoworkingWindow $window) => $window->starts_at->lessThanOrEqualTo($now) && $window->ends_at->greaterThan($now))
            ->pluck('space_id')
            ->all();

        return WalkinSession::query()
            ->whereNull('checked_out_at')
            ->whereIn('space_id', [...$coSpaces->modelKeys(), ...$lentNow])
            ->get(['id', 'user_id', 'membership_id']);
    }

    private function inBranch(Builder $space, Branch $branch): Builder
    {
        return $space->whereHas('building', fn (Builder $building) => $building->where('branch_id', $branch->id));
    }
}
