<?php

namespace App\Domain\Reports\Services\Dashboard;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Identity\Models\DeviceToken;
use App\Domain\Identity\Models\User;
use App\Domain\Reports\ValueObjects\DashboardPeriod;
use App\Domain\Services\Enums\RequestStatus;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use App\Support\PlainAmount;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The statistics widgets of the dashboard's home page: counts by status and
 * type, a count per day, and who uses the member app on which platform.
 * Each answers one card, under its own filters. Bookings and requests are
 * counted by the day they start or are submitted, on local days.
 */
final class StatsOverview
{
    public function __construct(private readonly LocalTime $time) {}

    /**
     * Every booking status with how many bookings starting in the period
     * ended in it — none left out, so a card always shows the same tiles.
     *
     * @return array<string, mixed>
     */
    public function bookingsByStatus(DashboardPeriod $period, ?Branch $branch, ?SpaceType $type): array
    {
        $counts = Booking::query()
            ->where('start_at', '>=', $period->start)
            ->where('start_at', '<', $period->end)
            ->when($branch || $type, fn (Builder $q) => $q->whereHas('space', fn (Builder $space) => $space
                ->when($type, fn (Builder $s) => $s->where('space_type', $type))
                ->when($branch, fn (Builder $s) => $s->whereHas('building', fn (Builder $b) => $b->where('branch_id', $branch->id)))))
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->toBase()
            ->pluck('aggregate', 'status');

        $byStatus = [];
        foreach (BookingStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return ['period' => $period->toArray(), 'total' => array_sum($byStatus), 'by_status' => $byStatus];
    }

    /**
     * Service requests submitted in the period, by status and by service
     * type, and the share of them resolved.
     *
     * @return array<string, mixed>
     */
    public function serviceRequests(DashboardPeriod $period, ?Branch $branch): array
    {
        $scope = fn () => ServiceRequest::query()
            ->where('submitted_at', '>=', $period->start)
            ->where('submitted_at', '<', $period->end)
            ->when($branch, fn (Builder $q) => $q->where('branch_id', $branch->id));

        $counts = $scope()->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->toBase()->pluck('aggregate', 'status');
        $byStatus = [];
        foreach (RequestStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }
        $total = array_sum($byStatus);

        $perType = $scope()->selectRaw('service_type_id, COUNT(*) AS aggregate')->groupBy('service_type_id')->toBase()->pluck('aggregate', 'service_type_id');
        $locale = app()->getLocale();
        $byType = ServiceType::query()
            ->whereKey($perType->keys())
            ->get()
            ->map(fn (ServiceType $type) => [
                'type' => ['id' => $type->id, 'name' => $type->translate('name', $locale)],
                'count' => (int) $perType[$type->id],
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'period' => $period->toArray(),
            'total' => $total,
            'by_status' => $byStatus,
            'by_type' => $byType,
            'resolution_rate' => $total === 0 ? null : PlainAmount::of(bcdiv((string) ($byStatus[RequestStatus::Resolved->value] * 100), (string) $total, 2)),
        ];
    }

    /**
     * How many bookings started on each local day of the period.
     *
     * @return array<string, mixed>
     */
    public function dailyBookings(DashboardPeriod $period, ?Branch $branch): array
    {
        $starts = Booking::query()
            ->where('start_at', '>=', $period->start)
            ->where('start_at', '<', $period->end)
            ->when($branch, fn (Builder $q) => $q->whereHas('space.building', fn (Builder $b) => $b->where('branch_id', $branch->id)))
            ->pluck('start_at');

        return ['period' => $period->toArray(), 'days' => $this->perDay($period, $starts)];
    }

    /**
     * How many members signed up on each local day of the period.
     *
     * @return array<string, mixed>
     */
    public function dailyMembers(DashboardPeriod $period, ?Branch $branch): array
    {
        $joined = User::role('member')
            ->where('created_at', '>=', $period->start)
            ->where('created_at', '<', $period->end)
            ->when($branch, fn (Builder $q) => $q->whereHas('branchMemberships', fn (Builder $m) => $m->where('branch_id', $branch->id)))
            ->pluck('created_at');

        return ['period' => $period->toArray(), 'days' => $this->perDay($period, $joined)];
    }

    /**
     * Members by the platform their app runs on — a member with both counts
     * on both — and those with no app registered at all.
     *
     * @return array<string, int>
     */
    public function appUsers(): array
    {
        $members = User::role('member');
        $onPlatform = fn (string $platform) => DeviceToken::query()
            ->where('platform', $platform)
            ->whereIn('user_id', User::role('member')->select('users.id'))
            ->distinct()
            ->count('user_id');

        return [
            'members' => (clone $members)->count(),
            'android' => $onPlatform('android'),
            'ios' => $onPlatform('ios'),
            'without_app' => (clone $members)->whereDoesntHave('deviceTokens')->count(),
        ];
    }

    /**
     * One entry per local day of the period, counting the instants on it.
     *
     * @param  iterable<\DateTimeInterface>  $instants
     * @return list<array{date: string, count: int}>
     */
    private function perDay(DashboardPeriod $period, iterable $instants): array
    {
        $timezone = $this->time->timezone();
        $counts = [];

        foreach ($instants as $instant) {
            $day = CarbonImmutable::instance($instant)->setTimezone($timezone)->format('Y-m-d');
            $counts[$day] = ($counts[$day] ?? 0) + 1;
        }

        $days = [];
        for ($day = CarbonImmutable::parse($period->from, $timezone); $day->format('Y-m-d') <= $period->to; $day = $day->addDay()) {
            $days[] = ['date' => $day->format('Y-m-d'), 'count' => $counts[$day->format('Y-m-d')] ?? 0];
        }

        return $days;
    }
}
