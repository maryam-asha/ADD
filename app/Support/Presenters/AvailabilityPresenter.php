<?php

namespace App\Support\Presenters;

use App\Domain\Booking\Enums\SlotState;
use App\Domain\Booking\ValueObjects\DayAvailability;
use App\Domain\Booking\ValueObjects\SlotAvailability;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Foundation\Models\Branch;
use App\Support\Time\LocalTime;

/**
 * Turns the availability value objects into the JSON the calendar screens
 * draw: the month grid, the slot chips, the branch-hours card, the closed
 * banner.
 *
 * Date and time formatting deliberately matches EventDatePresenter's
 * dialect ("SEP", "10", "THU", "September 10, 2026", "04:00 AM") so the
 * bookings calendar and the events calendar read the same way in the app.
 *
 * Every slot's `reason` is the translated message for the key the write
 * path would throw for that same window, which is the visible payoff of
 * both paths sharing AvailabilityService: the greyed-out chip's tooltip
 * and the eventual 422 are the same sentence.
 */
class AvailabilityPresenter
{
    public function __construct(
        private readonly LocalTime $time,
        private readonly BranchHoursPresenter $hours,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function day(DayAvailability $day): array
    {
        return [
            'date' => $day->date->format('Y-m-d'),
            'day' => $day->date->format('d'),
            'weekday' => strtoupper($day->date->format('D')),
            'month' => strtoupper($day->date->format('M')),
            'date_formatted' => $day->date->format('F j, Y'),
            'state' => $day->state->value,
            'is_selectable' => $day->isSelectable(),
            'available_slot_count' => $day->availableSlotCount,
            'total_slot_count' => $day->totalSlotCount,
        ];
    }

    /**
     * The red banner the mockup shows above the slot grid. Null unless the
     * branch is actually closed — a fully-booked day is open, and saying
     * "closed" there would be wrong.
     *
     * @return array<string, mixed>|null
     */
    public function closedBanner(DayAvailability $day, Branch $branch, string $locale): ?array
    {
        if (! $day->state->showsClosedBanner()) {
            return null;
        }

        return [
            'text' => __('api.booking.branch_closed_on_date', [
                'branch' => $this->branchName($branch, $locale),
            ], $locale),
            'hint' => __('api.booking.choose_another_day', [], $locale),
            // Null for a weekday that simply has no schedule; only an
            // explicit BusinessHourException can explain itself.
            'reason' => $day->closureReason,
            'severity' => 'error',
        ];
    }

    /**
     * @param  list<SlotAvailability>  $slots
     * @return list<array<string, mixed>>
     */
    public function slots(array $slots, string $locale): array
    {
        return array_map(fn (SlotAvailability $slot) => $this->slot($slot, $locale), $slots);
    }

    /**
     * @return array<string, mixed>
     */
    public function slot(SlotAvailability $slot, string $locale): array
    {
        $localStart = $this->time->toLocal($slot->startAt);
        $localEnd = $this->time->toLocal($slot->endAt);
        $reasonKey = $slot->reasonKey();

        return [
            'start_at' => $localStart->toIso8601String(),
            'end_at' => $localEnd->toIso8601String(),
            'label' => $localStart->format('h:i A'),
            'start_time' => $localStart->format('H:i'),
            'end_time' => $localEnd->format('H:i'),
            'state' => $slot->state->value,
            'is_available' => $slot->isAvailable(),
            'reason' => $reasonKey === null ? null : __($reasonKey, [], $locale),
        ];
    }

    /**
     * @param  list<SlotAvailability>  $slots
     * @return array<string, int>
     */
    public function slotCounts(array $slots): array
    {
        $counts = array_fill_keys(array_column(SlotState::cases(), 'value'), 0);

        foreach ($slots as $slot) {
            $counts[$slot->state->value]++;
        }

        $counts['total'] = count($slots);

        return $counts;
    }

    /**
     * The branch-hours card.
     *
     * Two different things live here, and the field names say which:
     *
     * - `hours` / `open_time` / `close_time` / `periods` are **one date's** —
     *   today's, or the date a day response is for.
     * - `schedule`, `open_weekdays` and `days_label` are **the ordinary week**,
     *   from the weekly schedule. A branch keeps different hours on different
     *   days, so `schedule` groups the days that share hours, each with its
     *   own — the way the dashboard's business-hours page groups them. Read
     *   together, one date's hours and "Saturday – Friday" used to say "every
     *   day, 08:00–22:00" of a week where Sunday and Friday differ.
     *
     * `upcoming_closures` is every all-day closure from today on
     * (BusinessHoursService::upcomingClosures()), not just those in the
     * range being shown, so a date picker can refuse a holiday in a month it
     * has not loaded.
     *
     * @param  array<int, array{open_time: string, close_time: string}>  $periods  one date's
     * @param  array<string, list<array{open_time: string, close_time: string}>>  $weeklySchedule  BusinessHoursService::weeklySchedule()
     * @param  list<array{date: string, reason: string|null}>  $upcomingClosures
     * @return array<string, mixed>
     */
    public function branchHours(
        Branch $branch,
        array $periods,
        array $weeklySchedule,
        SpaceBookingRules $rules,
        string $locale,
        array $upcomingClosures = [],
    ): array {
        $openTime = $periods === [] ? null : $periods[0]['open_time'];
        $closeTime = $periods === [] ? null : end($periods)['close_time'];
        $week = $this->hours->week($weeklySchedule, $rules->timezone, $locale);

        return [
            'title' => __('api.booking.branch_hours_title', [
                'branch' => $this->branchName($branch, $locale),
            ], $locale),
            'hours' => $openTime === null ? null : $this->hours->hoursLabel($periods, $rules->timezone),
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'periods' => $periods,
            'schedule' => $week['schedule'],
            'days_label' => $week['days_label'],
            'open_weekdays' => $week['open_weekdays'],
            'upcoming_closures' => $upcomingClosures,
            'timezone' => $rules->timezone,
            'timezone_label' => $this->hours->timezoneLabel($rules->timezone),
            'timezone_note' => __('api.booking.timezone_note', [
                'offset' => $this->hours->timezoneLabel($rules->timezone),
            ], $locale),
        ];
    }

    /**
     * The rules block the app needs to render its notices and constrain its
     * own inputs.
     *
     * No legend and no "greyed-out slots" note anywhere: the app draws both
     * from the three slot states in its own copy. No concurrency ceiling
     * either — it decides `booked` on the server and means nothing to the
     * app (the desk's calendar still shows it per space, where it scales
     * occupancy).
     *
     * @param  list<int>  $durationOptions
     * @return array<string, mixed>
     */
    public function rules(SpaceBookingRules $rules, string $locale, int $maxSelectableDates, array $durationOptions = []): array
    {
        return [
            'granularity_minutes' => $rules->granularityMinutes,
            'min_duration_minutes' => $rules->minDurationMinutes,
            // Null means no ceiling / no horizon.
            'min_notice_minutes' => $rules->minNoticeMinutes,
            'max_days_ahead' => $rules->maxDaysAhead,
            // Only lengths this space accepts: the chips a duration-first
            // picker shows before any start time is chosen.
            'duration_options_minutes' => $durationOptions,
            'buffer_minutes' => $rules->bufferMinutes,
            'cancellation_window_minutes' => $rules->cancellationWindowMinutes,
            'requires_approval' => $rules->requiresApproval,
            'approval_notice' => $rules->requiresApproval
                ? __('api.space.requires_approval_notice', [], $locale)
                : null,
            'max_selectable_dates' => $maxSelectableDates,
        ];
    }

    /**
     * "1h", "1.5h", "2h" — the chip labels in the duration selector.
     */
    public function durationLabel(int $minutes): string
    {
        $hours = $minutes / 60;

        return rtrim(rtrim(number_format($hours, 1), '0'), '.').'h';
    }

    private function branchName(Branch $branch, string $locale): string
    {
        return $branch->translate('name', $locale) ?? 'Aleppo Digital District';
    }
}
