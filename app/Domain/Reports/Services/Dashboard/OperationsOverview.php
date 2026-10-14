<?php

namespace App\Domain\Reports\Services\Dashboard;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\CoworkingPoolService;
use App\Domain\Ecosystem\Enums\SiteInquiryStatus;
use App\Domain\Ecosystem\Models\SiteInquiry;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use App\Domain\Reports\ValueObjects\DashboardPeriod;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceRequest;
use App\Support\PlainAmount;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operations section of the dashboard's home page: who is inside and
 * who is expected now, what bookings came to over the period, and what is
 * waiting on staff. Counts use the same scopes as the screens they point
 * to (reception, the seat pool), so the two never disagree. Seat occupancy
 * is shown for now only: the seat pool keeps no record of past walk-ins,
 * so an average over a past period would undercount.
 */
final class OperationsOverview
{
    public function __construct(
        private readonly CoworkingPoolService $pool,
        private readonly LocalTime $time,
    ) {}

    /** @return array<string, mixed> */
    public function for(DashboardPeriod $period, ?Branch $branch): array
    {
        $previous = $period->previous();

        return [
            'period' => $period->toArray(),
            'previous_period' => $previous->toArray(),
            'now' => $this->now($branch),
            'activity' => [
                'current' => $this->activity($period, $branch),
                'previous' => $this->activity($previous, $branch),
            ],
            'attention' => $this->attention($branch),
        ];
    }

    /** @return array<string, mixed> */
    private function now(?Branch $branch): array
    {
        $now = CarbonImmutable::now()->utc();
        $endOfToday = $now->setTimezone($this->time->timezone())->endOfDay()->utc();
        $branches = $branch !== null
            ? collect([$branch])
            : Branch::query()->where('is_active', true)->orderBy('id')->get();
        $locale = app()->getLocale();

        return [
            'active_sessions' => $this->inBranch(Booking::query()->inProgress(), $branch)->count()
                + $this->inBranch(WalkinSession::query()->open(), $branch)->count(),
            'expected_today' => $this->inBranch(Booking::query()->expectedBy($now, $endOfToday), $branch)->count(),
            'branches' => $branches->map(function (Branch $b) use ($now, $locale) {
                $pool = $this->pool->timeline($b, $now, $now->addSecond());

                return [
                    'id' => $b->id,
                    'name' => $b->translate('name', $locale),
                    'seats' => $pool->capacityAt($now),
                    'taken' => $pool->usageAt($now),
                ];
            })->values()->all(),
        ];
    }

    /**
     * Bookings starting in the period by outcome, and walk-ins started in
     * it. No-shows are measured against the bookings that came due
     * (completed or no-show), cancellations against every booking.
     *
     * @return array<string, int|string|null>
     */
    private function activity(DashboardPeriod $period, ?Branch $branch): array
    {
        $byStatus = $this->inBranch(Booking::query(), $branch)
            ->where('start_at', '>=', $period->start)
            ->where('start_at', '<', $period->end)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status')
            ->map(fn ($count) => (int) $count);

        $total = (int) $byStatus->sum();
        $completed = $byStatus[BookingStatus::Completed->value] ?? 0;
        $noShow = $byStatus[BookingStatus::NoShow->value] ?? 0;
        $cancelled = $byStatus[BookingStatus::Cancelled->value] ?? 0;

        return [
            'bookings' => $total,
            'completed' => $completed,
            'no_show' => $noShow,
            'cancelled' => $cancelled,
            'no_show_rate' => $this->rate($noShow, $completed + $noShow),
            'cancellation_rate' => $this->rate($cancelled, $total),
            'walk_ins' => $this->inBranch(WalkinSession::query(), $branch)
                ->where('checked_in_at', '>=', $period->start)
                ->where('checked_in_at', '<', $period->end)
                ->count(),
        ];
    }

    /** @return array<string, int> */
    private function attention(?Branch $branch): array
    {
        return [
            'bookings_awaiting_approval' => $this->inBranch(Booking::query()->where('status', BookingStatus::Pending), $branch)->count(),
            'bookings_awaiting_payment' => $this->inBranch(Booking::query()->where('status', BookingStatus::AwaitingPayment), $branch)->count(),
            'open_service_requests' => ServiceRequest::query()
                ->whereIn('status', [RequestStatus::Pending, RequestStatus::InProgress])
                ->when($branch, fn (Builder $q) => $q->where('branch_id', $branch->id))
                ->count(),
            // Neither belongs to a branch: counted whatever the filter.
            'new_site_inquiries' => SiteInquiry::query()->where('status', SiteInquiryStatus::New)->count(),
            'unquoted_office_requests' => PrivateOfficeRequest::query()->where('status', PrivateOfficeRequestStatus::Requested)->count(),
        ];
    }

    /** A query on rows that sit in a space, kept to one branch's spaces. */
    private function inBranch(Builder $query, ?Branch $branch): Builder
    {
        return $query->when($branch, fn (Builder $q) => $q->whereHas(
            'space.building',
            fn (Builder $building) => $building->where('branch_id', $branch->id),
        ));
    }

    /** $part of $whole as a percentage; null when there is nothing to measure. */
    private function rate(int $part, int $whole): ?string
    {
        return $whole === 0 ? null : PlainAmount::of(bcdiv((string) ($part * 100), (string) $whole, 2));
    }
}
