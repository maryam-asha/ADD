<?php

namespace App\Support\Time;

use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The one place that knows what "local" means.
 *
 * Instants are stored in UTC and displayed in the district's own timezone,
 * which comes from the global `app.timezone` Setting — not from
 * `branches.timezone`, a separate pre-existing column nothing reads
 * (docs/decisions/business-hours.md, and BusinessHoursService's docblock
 * says the same).
 *
 * Without this, a time entered as 11:30 PM and rendered without conversion
 * reads as 2:30 AM the *next day* once it is interpreted as UTC — the date
 * rolls over, not just the clock, taking the weekday, the upcoming/past
 * ordering and every deadline with it.
 */
class LocalTime
{
    public function __construct(private readonly SettingService $settings) {}

    public function timezone(): string
    {
        return (string) $this->settings->get('app.timezone', 'Asia/Damascus');
    }

    /**
     * Read a wall-clock time the way the person entering it meant it, and
     * return the UTC instant to store.
     *
     * A value that already carries an offset ("...Z", "+03:00") is taken at
     * its word; a bare "2026-10-01 19:00" means 19:00 here.
     */
    public function toUtc(string $value): Carbon
    {
        $timezone = $this->carriesOffset($value) ? null : $this->timezone();

        return Carbon::parse($value, $timezone)->utc();
    }

    /**
     * Turn a stored instant into local wall-clock time for display.
     */
    public function toLocal(CarbonInterface|string|null $instant): ?Carbon
    {
        if ($instant === null) {
            return null;
        }

        $carbon = $instant instanceof CarbonInterface
            ? $instant->copy()
            : Carbon::parse($instant);

        return Carbon::instance($carbon)->setTimezone($this->timezone());
    }

    /**
     * Whether the incoming string states its own offset, in which case the
     * client has already been explicit and we must not re-interpret it.
     */
    private function carriesOffset(string $value): bool
    {
        return (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value));
    }
}
