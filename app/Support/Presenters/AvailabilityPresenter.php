<?php

namespace App\Support\Presenters;

use App\Domain\Booking\ValueObjects\DayAvailability;
use App\Domain\Booking\ValueObjects\SlotAvailability;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Foundation\Models\Branch;
use App\Support\Time\LocalTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Turns the availability value objects into the JSON the calendar screens
 * draw: the month grid, the slot chips, the branch-hours card, the closed
 * banner and the legend.
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
    public function __construct(private readonly LocalTime $time) {}

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
        $counts = [
            'available' => 0,
            'booked' => 0,
            'outside_hours' => 0,
            'buffer' => 0,
            'at_capacity' => 0,
            'past' => 0,
        ];

        foreach ($slots as $slot) {
            $counts[$slot->state->value]++;
        }

        $counts['total'] = count($slots);

        return $counts;
    }

    /**
     * The "Main Branch Hours · 8:00 AM – 8:00 PM · Sunday – Thursday" card.
     *
     * `periods` here are today's; `open_weekdays` describes the weekly
     * shape, which is what the "Sunday – Thursday" line summarises.
     *
     * @param  array<int, array{open_time: string, close_time: string}>  $periods
     * @param  list<string>  $openWeekdays
     * @return array<string, mixed>
     */
    public function branchHours(
        Branch $branch,
        array $periods,
        array $openWeekdays,
        SpaceBookingRules $rules,
        string $locale,
    ): array {
        $openTime = $periods === [] ? null : $periods[0]['open_time'];
        $closeTime = $periods === [] ? null : end($periods)['close_time'];

        return [
            'title' => __('api.booking.branch_hours_title', [
                'branch' => $this->branchName($branch, $locale),
            ], $locale),
            'hours' => $openTime === null ? null : sprintf(
                '%s – %s',
                Carbon::parse($openTime, $rules->timezone)->format('g:i A'),
                Carbon::parse($closeTime, $rules->timezone)->format('g:i A'),
            ),
            'open_time' => $openTime,
            'close_time' => $closeTime,
            'periods' => $periods,
            'days_label' => $this->weekdayRangeLabel($openWeekdays),
            'open_weekdays' => $openWeekdays,
            'timezone' => $rules->timezone,
            'timezone_label' => $this->timezoneLabel($rules->timezone),
            'timezone_note' => __('api.booking.timezone_note', [
                'offset' => $this->timezoneLabel($rules->timezone),
            ], $locale),
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    public function legend(string $locale): array
    {
        return [
            ['key' => 'available', 'label' => __('api.booking.legend.available', [], $locale), 'style' => 'solid'],
            ['key' => 'booked', 'label' => __('api.booking.legend.booked', [], $locale), 'style' => 'muted'],
            ['key' => 'outside_hours', 'label' => __('api.booking.legend.outside_hours', [], $locale), 'style' => 'dashed'],
        ];
    }

    /**
     * The rules block the app needs to render its notices and constrain its
     * own inputs, including the two lang keys that were written for this
     * screen and had no consumer until now.
     *
     * @return array<string, mixed>
     */
    public function rules(SpaceBookingRules $rules, string $locale, int $maxSelectableDates): array
    {
        return [
            'granularity_minutes' => $rules->granularityMinutes,
            'min_duration_minutes' => $rules->minDurationMinutes,
            'buffer_minutes' => $rules->bufferMinutes,
            'cancellation_window_minutes' => $rules->cancellationWindowMinutes,
            'requires_approval' => $rules->requiresApproval,
            'approval_notice' => $rules->requiresApproval
                ? __('api.space.requires_approval_notice', [], $locale)
                : null,
            'min_duration_note' => __('api.space.min_duration_note', [
                'granularity' => $rules->granularityMinutes,
                'min_duration' => $this->durationLabel($rules->minDurationMinutes),
            ], $locale),
            'slots_note' => __('api.booking.slots_greyed_note', [], $locale),
            'concurrency_ceiling' => $rules->concurrencyCeiling,
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

    /**
     * Derived at request time, never stored: Damascus observes DST, so a
     * hardcoded "GMT+3" would be wrong for part of the year.
     */
    public function timezoneLabel(string $timezone): string
    {
        $offset = CarbonImmutable::now($timezone)->format('P');
        [$hours, $minutes] = explode(':', $offset);

        $sign = str_starts_with($hours, '-') ? '-' : '+';
        $hour = (int) ltrim($hours, '+-');

        return $minutes === '00'
            ? sprintf('GMT%s%d', $sign, $hour)
            : sprintf('GMT%s%d:%s', $sign, $hour, $minutes);
    }

    /**
     * Collapses the open weekdays into "Sunday – Thursday" when they form
     * one unbroken run, and falls back to a comma list when they do not —
     * a branch open Sunday, Tuesday and Thursday must not be described as
     * "Sunday – Thursday".
     *
     * @param  list<string>  $openWeekdays
     */
    private function weekdayRangeLabel(array $openWeekdays): ?string
    {
        if ($openWeekdays === []) {
            return null;
        }

        $order = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
        $indexes = array_values(array_filter(array_map(
            fn (string $day) => array_search($day, $order, true),
            $openWeekdays
        ), fn ($index) => $index !== false));

        sort($indexes);

        $label = fn (int $index) => ucfirst($order[$index]);

        if (count($indexes) === 1) {
            return $label($indexes[0]);
        }

        $isContiguous = count($indexes) - 1 === $indexes[count($indexes) - 1] - $indexes[0];

        return $isContiguous
            ? sprintf('%s – %s', $label($indexes[0]), $label($indexes[count($indexes) - 1]))
            : implode(', ', array_map($label, $indexes));
    }

    private function branchName(Branch $branch, string $locale): string
    {
        return $branch->translate('name', $locale) ?? 'Aleppo Digital District';
    }
}
