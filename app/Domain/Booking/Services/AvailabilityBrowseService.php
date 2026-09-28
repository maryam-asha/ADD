<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\DayState;
use App\Domain\Booking\Enums\SlotState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\ValueObjects\SlotAvailability;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Foundation\Enums\DayOfWeek;
use App\Domain\Foundation\Enums\OperationalStatus;
use App\Domain\Foundation\Models\BusinessHour;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Settings\Services\SettingService;
use App\Support\Presenters\AvailabilityPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Assembles the three member-facing availability responses: the month
 * grid, one day's slots, and the price/duration quote for a chosen start.
 *
 * All rule evaluation belongs to AvailabilityService; this class only
 * arranges the results and hands them to AvailabilityPresenter. Keeping
 * the split means a calendar screen can never introduce a rule of its own
 * (tests/Guards/AvailabilityRulesAreCentralizedTest).
 */
class AvailabilityBrowseService
{
    /**
     * A month view plus a little either side. Bounded because the query
     * cost is linear in days and an unbounded range is a cheap way to
     * make the server do a lot of work.
     */
    public const MAX_CALENDAR_DAYS = 62;

    /**
     * How many dates the multi-select strip may carry into one batch —
     * the same cap the batch endpoint validates against.
     */
    public const MAX_SELECTABLE_DATES = 10;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BusinessHoursService $businessHours,
        private readonly AvailabilityPresenter $presenter,
        private readonly SettingService $settings,
        private readonly PointConversionService $points,
    ) {}

    /**
     * The month grid: one entry per date with its state and, when closed,
     * its banner.
     *
     * @return array<string, mixed>
     */
    public function calendar(Space $space, ?string $from, ?string $to, string $locale, ?int $durationMinutes = null): array
    {
        $space = $this->assertBookable($space);
        $rules = $this->availability->rulesFor($space);
        $this->assertAcceptedDuration($durationMinutes, $rules, $locale);
        $branch = $space->building->branch;

        [$start, $end] = $this->resolveRange($from, $to, $rules->timezone);

        $days = $this->availability->daysInRange($space, $start, $end, $rules, $durationMinutes);

        $today = CarbonImmutable::now($rules->timezone)->startOfDay();
        $todayPeriods = $this->businessHours->periodsFor($today, $branch);

        return [
            'space' => $this->spaceSummary($space, $locale),
            'branch_hours' => $this->presenter->branchHours(
                $branch,
                $todayPeriods,
                $this->openWeekdays($branch->id),
                $rules,
                $locale,
                $this->businessHours->upcomingClosures($branch),
            ),
            'rules' => $this->presenter->rules($rules, $locale, self::MAX_SELECTABLE_DATES, $this->acceptedDurationOptions($rules)),
            'legend' => $this->presenter->legend($locale),
            'data' => array_map(fn ($day) => [
                ...$this->presenter->day($day),
                'closed_banner' => $this->presenter->closedBanner($day, $branch, $locale),
            ], $days),
            'meta' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
                'timezone' => $rules->timezone,
                'total' => count($days),
                'duration_minutes' => $durationMinutes,
            ],
        ];
    }

    /**
     * One day's chips.
     *
     * @return array<string, mixed>
     */
    public function day(Space $space, string $date, string $locale, ?int $durationMinutes = null): array
    {
        $space = $this->assertBookable($space);
        $rules = $this->availability->rulesFor($space);
        $this->assertAcceptedDuration($durationMinutes, $rules, $locale);
        $branch = $space->building->branch;

        $localDate = CarbonImmutable::parse($date, $rules->timezone)->startOfDay();

        // Reuse the month projection for one day so the chip states and the
        // grid cell can never disagree about the same date.
        $day = $this->availability->daysInRange($space, $localDate, $localDate, $rules, $durationMinutes)[0];
        $slots = $day->state === DayState::Closed
            ? []
            : $this->availability->slotsForDay($space, $localDate, $rules, durationMinutes: $durationMinutes);

        return [
            'date' => $this->presenter->day($day),
            'state' => $day->state->value,
            'closed_banner' => $this->presenter->closedBanner($day, $branch, $locale),
            'branch_hours' => $this->presenter->branchHours(
                $branch,
                $day->periods,
                $this->openWeekdays($branch->id),
                $rules,
                $locale,
                $this->businessHours->upcomingClosures($branch),
            ),
            'rules' => $this->presenter->rules($rules, $locale, self::MAX_SELECTABLE_DATES, $this->acceptedDurationOptions($rules)),
            'legend' => $this->presenter->legend($locale),
            'data' => $durationMinutes === null
                ? $this->presenter->slots($slots, $locale)
                : array_map(fn (SlotAvailability $slot) => [
                    ...$this->presenter->slot($slot, $locale),
                    ...$this->priced($space, $durationMinutes),
                ], $slots),
            'counts' => $this->presenter->slotCounts($slots),
            'meta' => [
                'granularity_minutes' => $rules->granularityMinutes,
                'timezone' => $rules->timezone,
                'duration_minutes' => $durationMinutes,
            ],
        ];
    }

    /**
     * The duration selector for a chosen start: each option's end time,
     * price and whether it is actually bookable.
     *
     * Read-only — nothing is held. A member who waits can still lose the
     * slot, which is why the write path re-checks under a lock.
     *
     * @return array<string, mixed>
     */
    public function quote(Space $space, string $startAt, string $locale): array
    {
        $space = $this->assertBookable($space);
        $rules = $this->availability->rulesFor($space);

        // AvailabilityQuoteRequest has already run this through
        // InterpretsLocalTimes, so what arrives here is a UTC wall-clock
        // string. Re-parsing it in the district timezone would convert it a
        // second time and quote a window three hours from the one the
        // member picked.
        $start = CarbonImmutable::parse($startAt, 'UTC')->setTimezone($rules->timezone);
        $ratePoints = (int) ($space->hourly_rate_points ?? 0);

        $options = $this->durationOptions($rules);
        $longest = max($options);

        // One fetch covering the longest option, reused by every option.
        $existing = $this->availability->blockingBookings(
            $space,
            $start->utc(),
            $start->addMinutes($longest)->utc(),
            $rules,
        );

        $periods = $this->businessHours->periodsFor($start, $space->building->branch);

        return [
            'start_at' => $start->toIso8601String(),
            'start_time' => $start->format('h:i A'),
            'hourly_rate_points' => $ratePoints,
            'hourly_rate_usd' => $this->points->pointsToUsd($ratePoints),
            'durations' => array_map(
                fn (int $minutes) => $this->durationOption($space, $start, $minutes, $rules, $existing, $periods, $locale),
                $options,
            ),
            'meta' => [
                'min_duration_minutes' => $rules->minDurationMinutes,
                'granularity_minutes' => $rules->granularityMinutes,
                'timezone' => $rules->timezone,
            ],
        ];
    }

    /**
     * One duration chip. `available` is false with a stated reason rather
     * than the option being hidden, so the app can grey it out and explain
     * why — the mockup shows disabled chips, not a shrinking list.
     *
     * @param  Collection<int, Booking>  $existing
     * @param  array<int, array{open_time: string, close_time: string}>  $periods
     * @return array<string, mixed>
     */
    private function durationOption(
        Space $space,
        CarbonImmutable $start,
        int $minutes,
        SpaceBookingRules $rules,
        Collection $existing,
        array $periods,
        string $locale,
    ): array {
        $end = $start->addMinutes($minutes);
        $startUtc = $start->utc();
        $endUtc = $end->utc();

        $state = match (true) {
            ! $this->availability->isFarEnoughAhead($startUtc, $rules) => SlotState::TooSoon,
            ! $this->availability->isWithinAdvanceHorizon($startUtc, $rules) => SlotState::TooFarAhead,
            ! $this->availability->fitsWithinPeriods($startUtc, $endUtc, $periods, $rules) => SlotState::OutsideHours,
            $this->availability->exceedsCeiling($existing, $startUtc, $endUtc, $rules) => $rules->allowsConcurrentBookings()
                ? SlotState::AtCapacity
                : SlotState::Booked,
            $this->availability->violatesBuffer($existing, $startUtc, $endUtc, $rules) => SlotState::Buffer,
            default => SlotState::Available,
        };

        // A configured option that does not fit this space's granularity is
        // returned disabled rather than silently offered.
        $fitsRules = $this->availability->isValidDuration($startUtc, $endUtc, $rules);
        $tooLong = ! $this->availability->isShortEnough($startUtc, $endUtc, $rules);
        $points = $this->pointsFor($space, $minutes);
        $reasonKey = $state->reasonKey();

        return [
            'minutes' => $minutes,
            'label' => $this->presenter->durationLabel($minutes),
            'end_at' => $end->toIso8601String(),
            'end_time' => $end->format('h:i A'),
            'points' => $points,
            'amount_usd' => $this->points->pointsToUsd($points),
            'available' => $state->isBookable() && $fitsRules,
            'reason' => match (true) {
                ! $state->isBookable() => __($reasonKey, [], $locale),
                $tooLong => __('api.booking.duration_too_long', [], $locale),
                ! $fitsRules => __('api.booking.duration_invalid_granularity', [], $locale),
                default => null,
            },
        ];
    }

    /**
     * Whole points, rounded up, for a duration — the same ceil-on-remainder
     * rule AmountCalculator applies to a real charge, so the quote never
     * under-states what the booking will cost.
     */
    private function pointsFor(Space $space, int $minutes): int
    {
        $rate = (int) ($space->hourly_rate_points ?? 0);

        if ($rate <= 0) {
            return 0;
        }

        return (int) ceil($rate * $minutes / 60);
    }

    /**
     * The price fields a duration-aware slot carries. The same ceil rule as
     * the quote; the rate is flat today, so every slot of one duration costs
     * the same, but the price rides on the slot so a future peak rate has
     * somewhere to go without a client change.
     *
     * @return array{duration_minutes: int, points: int, amount_usd: mixed}
     */
    private function priced(Space $space, int $minutes): array
    {
        $points = $this->pointsFor($space, $minutes);

        return [
            'duration_minutes' => $minutes,
            'points' => $points,
            'amount_usd' => $this->points->pointsToUsd($points),
        ];
    }

    /**
     * A requested duration the space would never accept is a bad request,
     * not an empty grid: an empty grid reads as "fully booked", which would
     * send the member hunting through other days for nothing. The message
     * is the one the POST would give for the same length.
     */
    private function assertAcceptedDuration(?int $minutes, SpaceBookingRules $rules, string $locale): void
    {
        if ($minutes === null || $this->availability->isValidDurationInMinutes($minutes, $rules)) {
            return;
        }

        $key = match (true) {
            $minutes < $rules->minDurationMinutes => 'api.booking.duration_too_short',
            $rules->maxDurationMinutes !== null && $minutes > $rules->maxDurationMinutes => 'api.booking.duration_too_long',
            default => 'api.booking.duration_invalid_granularity',
        };

        throw ValidationException::withMessages(['duration' => [__($key, [], $locale)]]);
    }

    /**
     * The ladder narrowed to lengths this space accepts — what the
     * duration-first picker offers before any start time is chosen.
     *
     * @return list<int>
     */
    private function acceptedDurationOptions(SpaceBookingRules $rules): array
    {
        return array_values(array_filter(
            $this->durationOptions($rules),
            fn (int $minutes) => $this->availability->isValidDurationInMinutes($minutes, $rules),
        ));
    }

    /**
     * The configured duration ladder, filtered to whole positive values.
     *
     * @return list<int>
     */
    private function durationOptions(SpaceBookingRules $rules): array
    {
        $configured = $this->settings->get('booking.duration_options_minutes', [60, 90, 120, 180]);

        if (is_string($configured)) {
            $configured = json_decode($configured, true) ?: [];
        }

        $options = collect((array) $configured)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $options === [] ? [$rules->minDurationMinutes] : $options;
    }

    /**
     * Which weekdays the branch has any schedule for — the "Sunday –
     * Thursday" line on the hours card.
     *
     * @return list<string>
     */
    private function openWeekdays(int $branchId): array
    {
        return BusinessHour::query()
            ->where('branch_id', $branchId)
            ->get(['day_of_week'])
            ->map(fn (BusinessHour $hour) => $hour->day_of_week instanceof DayOfWeek
                ? $hour->day_of_week->value
                : (string) $hour->day_of_week)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveRange(?string $from, ?string $to, string $timezone): array
    {
        $start = $from !== null
            ? CarbonImmutable::parse($from, $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfMonth();

        $end = $to !== null
            ? CarbonImmutable::parse($to, $timezone)->startOfDay()
            : $start->endOfMonth()->startOfDay();

        if ($end->lessThan($start)) {
            $end = $start;
        }

        return [$start, $end];
    }

    /**
     * A non-active space disappears from booking surfaces entirely
     * (PRD §5.6) — 404 rather than an empty calendar, so the app does not
     * render a bookable-looking screen for a retired room.
     */
    private function assertBookable(Space $space): Space
    {
        if ($space->status !== OperationalStatus::Active) {
            throw new NotFoundHttpException(__('api.system.not_found'));
        }

        return $space->loadMissing(['building.branch']);
    }

    /**
     * @return array<string, mixed>
     */
    private function spaceSummary(Space $space, string $locale): array
    {
        return [
            'id' => $space->id,
            'title' => $space->translate('name', $locale),
            'space_type' => $space->space_type->value,
            'capacity' => $space->capacity,
            'main_image' => $space->main_image,
        ];
    }
}
