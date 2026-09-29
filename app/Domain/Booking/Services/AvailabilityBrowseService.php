<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\DayState;
use App\Domain\Booking\ValueObjects\SlotAvailability;
use App\Domain\Booking\ValueObjects\SpaceBookingRules;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Support\Presenters\AvailabilityPresenter;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Assembles the availability responses: a space's booking setup (for the
 * member's space details) and one day's slots (member and desk).
 *
 * There is no duration anywhere here. A day's slots are the space's own
 * cells, `granularity_minutes` each, and a booking is a run of consecutive
 * free cells at least `min_duration_minutes` long, picked by its first and
 * last cell — in the member app and on the desk alike. The POST re-checks
 * the whole run.
 *
 * All rule evaluation belongs to AvailabilityService; this class only
 * arranges the results and hands them to AvailabilityPresenter. Keeping
 * the split means a calendar screen can never introduce a rule of its own
 * (tests/Guards/AvailabilityRulesAreCentralizedTest).
 */
class AvailabilityBrowseService
{
    /**
     * How many dates the multi-select strip may carry into one batch —
     * the same cap the batch endpoint validates against.
     */
    public const MAX_SELECTABLE_DATES = 10;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly BusinessHoursService $businessHours,
        private readonly AvailabilityPresenter $presenter,
    ) {}

    /**
     * What this space's booking calendar is set up with and that does not
     * change with the date: its booking rules and the dates it must refuse.
     *
     * The member app reads this once, with the space details, and then asks
     * `day()` only for what does change. The branch's week is not here: it
     * is the same for every space, so the member app reads it once from the
     * spaces list's `working_hours_banner`. The rules read the same settings
     * as the desk calendar's `booking_window`, and the closures come from the
     * same BusinessHoursService call as the desk's card, so none of them can
     * disagree.
     *
     * `upcoming_closures` is every all-day closure from today on as ISO-8601
     * date-times at local midnight with no offset ("2026-09-30T00:00:00") —
     * no reason, which the member calendar does not show. The app passes
     * each straight to DateTime.parse, which reads an offset-less value as
     * local time, so the 30th stays the 30th on any device. With an offset
     * ("…T00:00:00+03:00") a device set to UTC would move it to the 29th and
     * grey out the wrong day.
     *
     * @return array{rules: array<string, mixed>, upcoming_closures: list<string>}
     */
    public function bookingSetup(Space $space, string $locale): array
    {
        $space = $this->assertBookable($space);

        return [
            'rules' => $this->rulesBlock($this->availability->rulesFor($space), $locale),
            'upcoming_closures' => array_map(
                fn (string $date) => "{$date}T00:00:00",
                array_column($this->businessHours->upcomingClosures($space->building->branch), 'date'),
            ),
        ];
    }

    /**
     * One day's cells.
     *
     * Each cell is judged on itself — is this stretch of the day free — not
     * as the start of the shortest legal booking. A picker that takes a run
     * of cells needs the former: with 30-minute cells and a 60-minute
     * minimum, the cell just before an 11:00 booking cannot START a booking,
     * yet it is a perfectly good LAST cell of 10:00–11:00. The minimum is a
     * rule about the run, applied when it is picked and again on the POST.
     *
     * The day's `state` still asks whether any booking of the minimum length
     * can start that day, so a day of scattered half-hours reads as full.
     *
     * One response for the member app and the desk alike —
     * `{date, state, closed_banner, slots, counts, meta}` — carrying only
     * what changes with the date. The setup that does not (rules, closures,
     * the week) each reads once: the member app from the space details and
     * the spaces list, the desk from its calendar.
     *
     * @return array{date: string, state: string, closed_banner: array<string, mixed>|null, slots: list<array<string, mixed>>, counts: array<string, int>, meta: array{granularity_minutes: int, timezone: string}}
     */
    public function day(Space $space, string $date, string $locale): array
    {
        $space = $this->assertBookable($space);
        $rules = $this->availability->rulesFor($space);
        $branch = $space->building->branch;

        $localDate = CarbonImmutable::parse($date, $rules->timezone)->startOfDay();

        // The day's state comes from the same per-day projection the desk's
        // calendar uses, so the chips and the grid cell can never disagree.
        $day = $this->availability->daysInRange($space, $localDate, $localDate, $rules)[0];
        // A cell whose time to be booked has gone — started, or inside the
        // minimum notice — is left out for the member app and the desk alike:
        // nothing either can do with it. On today at 14:00 with an hour's
        // notice, the list starts at 15:00.
        $slots = $day->state === DayState::Closed
            ? []
            : array_values(array_filter(
                $this->availability->slotsForDay($space, $localDate, $rules, eachCellAlone: true),
                fn (SlotAvailability $slot) => ! $slot->state->isGone(),
            ));

        return [
            // Echoed so a client can drop a stale answer when dates are
            // tapped faster than they return.
            'date' => $localDate->format('Y-m-d'),
            'state' => $day->state->value,
            'closed_banner' => $this->presenter->closedBanner($day, $branch, $locale),
            'slots' => $this->presenter->slots($slots),
            'counts' => $this->presenter->slotCounts($slots),
            'meta' => [
                'granularity_minutes' => $rules->granularityMinutes,
                'timezone' => $rules->timezone,
            ],
        ];
    }

    /**
     * The one builder for the member's `rules`, in the space details.
     *
     * @return array<string, mixed>
     */
    private function rulesBlock(SpaceBookingRules $rules, string $locale): array
    {
        return $this->presenter->rules($rules, $locale, self::MAX_SELECTABLE_DATES);
    }

    /**
     * A non-active space disappears from booking surfaces entirely
     * (PRD §5.6) — 404 rather than an empty calendar, so the app does not
     * render a bookable-looking screen for a retired room.
     */
    private function assertBookable(Space $space): Space
    {
        if (! $this->availability->acceptsBookings($space)) {
            throw new NotFoundHttpException(__('api.system.not_found'));
        }

        return $space->loadMissing(['building.branch']);
    }
}
