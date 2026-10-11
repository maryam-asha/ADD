<?php

namespace App\Support\Presenters;

use App\Domain\Booking\Enums\SlotState;
use App\Domain\Booking\ValueObjects\DayAvailability;
use App\Domain\Booking\ValueObjects\SlotAvailability;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Foundation\Models\Branch;
use App\Support\Time\LocalTime;

/**
 * Turns the availability value objects into the JSON the booking screens
 * draw: the slot chips, the branch-hours card, the closed banner, the rules.
 *
 * Time formatting deliberately matches EventDatePresenter's dialect
 * ("04:00 AM") so the bookings calendar and the events calendar read the
 * same way in the app.
 */
class AvailabilityPresenter
{
    /**
     * A slot's `start_time` / `end_time`: "01:00 PM". The one format the
     * member app and the dashboard both show and post back.
     */
    public const SLOT_TIME_FORMAT = 'h:i A';

    public function __construct(
        private readonly LocalTime $time,
        private readonly BranchHoursPresenter $hours,
    ) {}

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
         
        ];
    }

    /**
     * @param  list<SlotAvailability>  $slots
     * @return list<array<string, mixed>>
     */
    public function slots(array $slots): array
    {
        return array_map(fn (SlotAvailability $slot) => $this->slot($slot), $slots);
    }

    /**
     * One cell, the same for the member app and the desk: its two times, its
     * state and whether it can be picked — nothing else.
     *
     * The times are 12-hour `hh:mm AM|PM` and nothing else — no label in
     * another format beside them — so the app and the dashboard show the very
     * value they post back, and neither converts. AM/PM stays in Latin letters
     * whatever the language: PHP's `A` is not translated, and a value posted
     * back as "{date} {start_time}" has to parse. No instants: they are the
     * day's `date` and these times, and a run's length is its cells ×
     * `meta.granularity_minutes`.
     *
     * The state is one of the two a picker acts on — available or booked
     * (SlotState::forMember()): a buffer, a full shared space or a date
     * beyond the window all mean "not this cell". No `reason` either: a
     * refusal still met is explained by the POST's own 422, in the same
     * words the engine would have put on the chip.
     *
     * @return array{start_time: string, end_time: string, state: string, is_available: bool}
     */
    public function slot(SlotAvailability $slot): array
    {
        return [
            'start_time' => $this->time->toLocal($slot->startAt)->format(self::SLOT_TIME_FORMAT),
            'end_time' => $this->time->toLocal($slot->endAt)->format(self::SLOT_TIME_FORMAT),
            'state' => $slot->state->forMember()->value,
            'is_available' => $slot->isAvailable(),
        ];
    }

    /**
     * `{available, booked, total}` over the cells sent.
     *
     * @param  list<SlotAvailability>  $slots
     * @return array<string, int>
     */
    public function slotCounts(array $slots): array
    {
        $counts = array_fill_keys(array_column(SlotState::memberStates(), 'value'), 0);

        foreach ($slots as $slot) {
            $counts[$slot->state->forMember()->value]++;
        }

        return [...$counts, 'total' => count($slots)];
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
     * No list of lengths: a booking is a run of cells at least
     * `min_duration_minutes` long, with no ceiling.
     *
     * @return array<string, mixed>
     */
    public function rules(SpaceBookingRules $rules, string $locale, int $maxSelectableDates): array
    {
        return [
            'granularity_minutes' => $rules->granularityMinutes,
            'min_duration_minutes' => $rules->minDurationMinutes,
            // Null means no ceiling / no horizon.
            'min_notice_minutes' => $rules->minNoticeMinutes,
            'max_days_ahead' => $rules->maxDaysAhead,
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
