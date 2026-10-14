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
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Plan;
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
        return $this->gather($branch, $from, $to, $candidate, baseOnly: false);
    }

    /**
     * Where a co-working presence over [$start, $end) should sit
     * (docs/decisions/coworking-seat-pool.md, "Seating placement"): null for
     * the branch's own co-working seats while they last, else the first room
     * lent to co-working whose window covers the whole stretch and still has
     * a seat. Null too when no single place fits — the pool as a whole is
     * the gate, this only tells the member where to go.
     *
     * With $stayInSpaceId — an extension of a booking already sitting in a
     * lent room — that room is tried first, so nobody is moved while their
     * own room still has them.
     */
    public function placeFor(Branch $branch, CarbonInterface $start, CarbonInterface $end, ?User $user, ?int $ignoreBookingId = null, ?int $stayInSpaceId = null): ?Space
    {
        $start = CarbonImmutable::instance($start)->utc();
        $end = CarbonImmutable::instance($end)->utc();

        $windows = SpaceCoworkingWindow::query()
            ->with('space')
            ->where('starts_at', '<=', $start)
            ->where('ends_at', '>=', $end)
            ->whereHas('space', fn (Builder $space) => $this->inBranch($space, $branch))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $current = $windows->firstWhere('space_id', $stayInSpaceId);

        if ($current !== null && $this->roomHasSeat($current, $start, $end, $ignoreBookingId)) {
            return $current->space;
        }

        $candidate = PoolDemand::presence($start, $end, $user, ignoreBookingId: $ignoreBookingId);

        if ($this->gather($branch, $start, $end, $candidate, baseOnly: true)->minFree() >= 0) {
            return null;
        }

        return $windows->first(fn (SpaceCoworkingWindow $window) => $this->roomHasSeat($window, $start, $end, $ignoreBookingId))?->space;
    }

    /**
     * Whether one more person fits in a lent room over [$start, $end).
     */
    private function roomHasSeat(SpaceCoworkingWindow $window, CarbonImmutable $start, CarbonImmutable $end, ?int $ignoreBookingId): bool
    {
        return $this->roomTimeline($window, $start, $end, $ignoreBookingId, withCandidate: true)->minFree() >= 0;
    }

    /**
     * A lent room's own seats over [$from, $to) (clipped to its window):
     * the bookings placed in it and the walk-ins inside now, against its
     * `seats` — with one more person when $withCandidate.
     */
    public function roomTimeline(SpaceCoworkingWindow $window, CarbonInterface $from, CarbonInterface $to, ?int $ignoreBookingId = null, bool $withCandidate = false): PoolTimeline
    {
        $start = CarbonImmutable::instance($from)->utc();
        $end = CarbonImmutable::instance($to)->utc();

        $seated = Booking::query()
            ->blocking()
            ->overlapping($start, $end)
            ->where('seat_space_id', $window->space_id)
            ->when($ignoreBookingId, fn (Builder $query, int $id) => $query->whereKeyNot($id))
            ->get(['start_at', 'end_at'])
            ->map(fn (Booking $booking) => ['start' => $booking->start_at, 'end' => $booking->end_at, 'membership' => null])
            ->all();

        $now = CarbonImmutable::now()->utc();

        if ($now->greaterThanOrEqualTo($start) && $now->lessThan($end)) {
            $walkIns = WalkinSession::query()->whereNull('checked_out_at')->where('space_id', $window->space_id)->count();

            for ($i = 0; $i < $walkIns; $i++) {
                $seated[] = ['start' => $now, 'end' => $now->addSecond(), 'membership' => null];
            }
        }

        if ($withCandidate) {
            $seated[] = ['start' => $start, 'end' => $end, 'membership' => null];
        }

        $supply = [['start' => $window->starts_at, 'end' => $window->ends_at, 'seats' => $window->seats]];

        return PoolTimeline::build($start, $end, $supply, [], $seated);
    }

    /**
     * The pool split by place at one instant, for the dashboard: the
     * branch's own co-working seats, then each room lent to co-working then
     * — seats open, seats taken, and until when a room is lent. Counted as
     * placeFor() places people, so the two never disagree. The base's taken
     * seats include those subscriptions hold.
     *
     * @return list<array{kind: string, spaces: Collection<int, Space>, seats: int, taken: int, until: ?CarbonImmutable}>
     */
    public function placesAt(Branch $branch, CarbonInterface $at): array
    {
        $at = CarbonImmutable::instance($at)->utc();
        $next = $at->addSecond();
        $base = $this->gather($branch, $at, $next, null, baseOnly: true);

        $places = [[
            'kind' => 'base',
            'spaces' => Space::query()->whereKey($this->coSpaces($branch)->modelKeys())->with(['building', 'zone.floor'])->orderBy('id')->get(),
            'seats' => $base->capacityAt($at),
            'taken' => $base->usageAt($at),
            'until' => null,
        ]];

        $windows = SpaceCoworkingWindow::query()
            ->with(['space.building', 'space.zone.floor'])
            ->overlapping($at, $next)
            ->whereHas('space', fn (Builder $space) => $this->inBranch($space, $branch))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        foreach ($windows as $window) {
            $places[] = [
                'kind' => 'lent_room',
                'spaces' => new Collection([$window->space]),
                'seats' => $window->seats,
                'taken' => $this->roomTimeline($window, $at, $next)->usageAt($at),
                'until' => CarbonImmutable::instance($window->ends_at)->utc(),
            ];
        }

        return $places;
    }

    /**
     * The pool as it is expected to be over [$from, $to): the timeline,
     * with every subscription set to renew itself — auto_renew, its renewal
     * not stopped, none made yet — holding its seats to $to. It renews ahead
     * of its end (renewals:run) and would take them again.
     */
    public function forecast(Branch $branch, CarbonInterface $from, CarbonInterface $to): PoolTimeline
    {
        return $this->gather($branch, $from, $to, null, baseOnly: false, assumeRenewals: true);
    }

    /**
     * The earliest moment in [$from, $latest] a subscription to $plan
     * holding $seats would find them free for its whole period, while its
     * plan is open, as the pool is expected to be (forecast()); null when
     * none does. A forecast, not a hold: a sale made first can take them.
     */
    public function earliestSubscriptionStart(Branch $branch, Plan $plan, int $seats, CarbonInterface $from, CarbonInterface $latest): ?CarbonImmutable
    {
        $from = CarbonImmutable::instance($from)->utc();
        $latest = CarbonImmutable::instance($latest)->utc();
        $days = (int) $plan->duration_days;
        $to = $latest->addDays($days);

        // Every stretch, while the plan is open, with fewer than $seats free —
        // in order, since both the segments and the plan's windows are.
        $short = [];
        $windows = $plan->windowSegments($from, $to);
        $first = 0;

        foreach ($this->forecast($branch, $from, $to)->segments() as $segment) {
            if ($seats <= $segment['capacity'] - $segment['usage']) {
                continue;
            }

            while ($first < count($windows) && $windows[$first][1]->lessThanOrEqualTo($segment['start'])) {
                $first++;
            }

            for ($i = $first; $i < count($windows) && $windows[$i][0]->lessThan($segment['end']); $i++) {
                $short[] = [
                    $windows[$i][0]->greaterThan($segment['start']) ? $windows[$i][0] : $segment['start'],
                    $windows[$i][1]->lessThan($segment['end']) ? $windows[$i][1] : $segment['end'],
                ];
            }
        }

        // A start fits until a short stretch falls inside its period; the
        // next start worth trying is that stretch's end.
        $start = $from;

        foreach ($short as [$shortFrom, $shortUntil]) {
            if ($shortUntil->lessThanOrEqualTo($start)) {
                continue;
            }

            if ($shortFrom->greaterThanOrEqualTo($start->addDays($days))) {
                break;
            }

            $start = $shortUntil;
        }

        return $start->lessThanOrEqualTo($latest) ? $start : null;
    }

    /**
     * The pool's timeline; with $baseOnly, only the branch's own co-working
     * seats and the people sitting in them — the rooms lent to co-working,
     * and whoever was placed in one, left out. With $assumeRenewals, the
     * subscriptions set to renew themselves hold their seats to $to.
     */
    private function gather(Branch $branch, CarbonInterface $from, CarbonInterface $to, ?PoolDemand $candidate, bool $baseOnly, bool $assumeRenewals = false): PoolTimeline
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        $coSpaces = $this->coSpaces($branch);
        $windows = $baseOnly ? new Collection : SpaceCoworkingWindow::query()
            ->overlapping($from, $to)
            ->whereHas('space', fn (Builder $space) => $this->inBranch($space, $branch))
            ->get();

        $supply = [
            ...$coSpaces->map(fn (Space $space) => ['start' => $from, 'end' => $to, 'seats' => (int) ($space->capacity ?? 0)]),
            ...$windows->map(fn (SpaceCoworkingWindow $window) => ['start' => $window->starts_at, 'end' => $window->ends_at, 'seats' => $window->seats]),
        ];

        $memberships = $this->heldMemberships($branch, $from, $to);
        $renewing = $assumeRenewals ? $this->renewing($memberships) : [];
        $holds = [];

        foreach ($memberships as $membership) {
            $stretches = in_array($membership->id, $renewing, true)
                ? $membership->plan->windowSegments(max($from, CarbonImmutable::instance($membership->current_period_start)->utc()), $to)
                : $this->heldStretches($membership, $from, $to);

            foreach ($stretches as [$start, $end]) {
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
            ->when($baseOnly, fn (Builder $query) => $query->where(fn (Builder $seat) => $seat
                ->whereNull('seat_space_id')
                ->orWhereIn('seat_space_id', $coSpaces->modelKeys())))
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

    /**
     * The subscriptions set to renew themselves: auto_renew, the renewal not
     * stopped (renewal_stop_reason), and no renewal made yet — one already
     * made is a subscription of its own, counted as such.
     *
     * @param  Collection<int, Membership>  $memberships
     * @return list<int>
     */
    private function renewing(Collection $memberships): array
    {
        $renewed = Membership::query()->whereIn('renewed_from_id', $memberships->modelKeys())->pluck('renewed_from_id')->all();

        return $memberships
            ->filter(fn (Membership $membership) => $membership->auto_renew
                && $membership->renewal_stop_reason === null
                && ! in_array($membership->id, $renewed, true))
            ->modelKeys();
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
