<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use App\Domain\Foundation\Models\Space;
use App\Domain\Providers\Models\Provider;
use App\Domain\Settings\Services\SettingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Public catalog (two-tier Admin/Public controller, later phase).
 * `price_points` is whole ADD Points — a cardinal quantity, cast as an
 * integer rather than the decimal cast used for the old dollar columns.
 *
 * `access_window_start`/`access_window_end` are plain `H:i` strings, not a
 * Carbon cast — see the migration for why. They are deliberately NOT compared
 * lexically the way `BusinessHoursService` compares its own `H:i` columns:
 * that works only for windows that stay inside one calendar day, and the
 * evening package this was built for (20:00–00:00) is precisely the case it
 * gets wrong. `windowOverlap()` converts to minutes and handles the wrap.
 */
class Plan extends Model
{
    use HasFactory, HasTranslations;

    private const MINUTES_PER_DAY = 1440;

    protected $fillable = [
        'plan_module_id',
        'name',
        'subtitle',
        'is_subscription',
        'price_points',
        'reference_plan_id',
        'reference_space_id',
        'reference_units',
        'duration_days',
        'access_window_start',
        'access_window_end',
        'is_active',
        'is_public',
        'order',
        'provider_id',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'subtitle' => 'array',
            'is_subscription' => 'boolean',
            'price_points' => 'integer',
            'reference_units' => 'integer',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * The catalog filter for everything member- and public-facing. Admin
     * listings deliberately do not use it: operations has to see a private
     * plan in order to assign it to anyone.
     */
    public function scopePubliclyListable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function hasAccessWindow(): bool
    {
        return $this->access_window_start !== null && $this->access_window_end !== null;
    }

    /**
     * How many seconds of [$from, $to) fall inside this plan's daily access
     * window, in the app's local timezone.
     *
     * A window whose end is not after its start wraps past midnight, so
     * 20:00–00:00 covers 20:00→24:00, and 22:00–02:00 covers 22:00→24:00 plus
     * 00:00→02:00 the next day. An end of "00:00" therefore means midnight as
     * an end-of-day boundary, never a zero-length window — that is the exact
     * shape of the evening package this exists for.
     *
     * Returns 0 when the plan has no window, so a caller can treat "no window"
     * and "no overlap" identically only where that is intended; use
     * hasAccessWindow() where the distinction matters.
     */
    public function windowOverlap(CarbonInterface $from, CarbonInterface $to): int
    {
        if (! $this->hasAccessWindow()) {
            return 0;
        }

        return array_sum(array_map(
            fn (array $segment) => (int) $segment[0]->diffInSeconds($segment[1]),
            $this->windowSegments($from, $to),
        ));
    }

    /**
     * The stretches of [$from, $to) during which this plan is open, as UTC
     * intervals in order, each [start, end).
     *
     * The same window as windowOverlap() — which is their sum — for a caller
     * that needs WHEN, not how long: the co-working seat pool holds a
     * subscriber's seat only while their plan is open. A window that wraps
     * midnight is one stretch across it (22:00–02:00 is one segment, not two
     * touching ones).
     *
     * Unlike windowOverlap(), a plan with no window is open the whole time:
     * the result is [$from, $to] itself. A malformed window is never open.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function windowSegments(CarbonInterface $from, CarbonInterface $to): array
    {
        $rangeStart = CarbonImmutable::instance($from)->utc();
        $rangeEnd = CarbonImmutable::instance($to)->utc();

        if ($rangeEnd->lessThanOrEqualTo($rangeStart)) {
            return [];
        }

        if (! $this->hasAccessWindow()) {
            return [[$rangeStart, $rangeEnd]];
        }

        $windowStart = $this->minutesSinceMidnight($this->access_window_start);
        $windowEnd = $this->minutesSinceMidnight($this->access_window_end);

        if ($windowStart === null || $windowEnd === null) {
            return [];
        }

        $timezone = app(SettingService::class)->get('app.timezone', 'Asia/Damascus');

        // Each day contributes at most two pieces: the wrap case splits the
        // window into a head on this day and a tail on the next.
        $pieces = $windowEnd > $windowStart
            ? [[$windowStart, $windowEnd]]
            : [[$windowStart, self::MINUTES_PER_DAY], [0, $windowEnd]];

        $segments = [];

        // Walk local calendar days, not 24-hour blocks: DST would make the
        // latter drift off midnight. Start a day early so a wrapped window's
        // tail from the previous day is counted too.
        $cursor = $rangeStart->setTimezone($timezone)->startOfDay()->subDay();
        $limit = $rangeEnd->setTimezone($timezone)->startOfDay();

        while ($cursor->lessThanOrEqualTo($limit)) {
            foreach ($pieces as [$pieceStart, $pieceEnd]) {
                $start = max($rangeStart, $cursor->addMinutes($pieceStart)->utc());
                $end = min($rangeEnd, $cursor->addMinutes($pieceEnd)->utc());

                if ($end->lessThanOrEqualTo($start)) {
                    continue;
                }

                // A piece starting where the last one ended — the two halves of
                // a window across midnight — extends it.
                $last = array_key_last($segments);

                if ($last !== null && $segments[$last][1]->greaterThanOrEqualTo($start)) {
                    $segments[$last][1] = max($segments[$last][1], $end);
                } else {
                    $segments[] = [$start, $end];
                }
            }

            $cursor = $cursor->addDay();
        }

        return $segments;
    }

    /**
     * The end, as a UTC instant, of the window occurrence containing $at —
     * or null when $at is outside the window or there is no window. Same
     * wrap rules as windowOverlap(): 20:00–00:00 at 21:00 ends at the next
     * midnight; 22:00–02:00 at 01:00 ends at 02:00 that morning.
     */
    public function currentWindowEndsAt(CarbonInterface $at): ?CarbonImmutable
    {
        if (! $this->hasAccessWindow()) {
            return null;
        }

        $windowStart = $this->minutesSinceMidnight($this->access_window_start);
        $windowEnd = $this->minutesSinceMidnight($this->access_window_end);

        if ($windowStart === null || $windowEnd === null) {
            return null;
        }

        $timezone = app(SettingService::class)->get('app.timezone', 'Asia/Damascus');
        $local = CarbonImmutable::instance($at)->setTimezone($timezone);
        $minute = ($local->hour * 60) + $local->minute;
        $midnight = $local->startOfDay();

        if ($windowEnd > $windowStart) {
            return $minute >= $windowStart && $minute < $windowEnd
                ? $midnight->addMinutes($windowEnd)->utc()
                : null;
        }

        if ($minute >= $windowStart) {
            return $midnight->addDay()->addMinutes($windowEnd)->utc();
        }

        return $minute < $windowEnd ? $midnight->addMinutes($windowEnd)->utc() : null;
    }

    private function minutesSinceMidnight(string $time): ?int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }

    /** A Dedicated Desk plan ties its subscriptions to specific desks. */
    public function isDedicatedDesk(): bool
    {
        return $this->planModule?->slug === PlanModule::DEDICATED_DESK;
    }

    /** The plan this one's saving is measured against (PlanSavingCalculator). */
    public function referencePlan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_plan_id');
    }

    /** The space whose hourly rate this one's saving is measured against. */
    public function referenceSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'reference_space_id');
    }

    public function planModule(): BelongsTo
    {
        return $this->belongsTo(PlanModule::class, 'plan_module_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(PlanBalance::class)->orderBy('order');
    }

    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class)->orderBy('order');
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(PlanDiscount::class)->orderBy('order');
    }

    public function resourceGrants(): HasMany
    {
        return $this->hasMany(PlanResourceGrant::class)->orderBy('order');
    }

    /** The provider that designed this plan; null for ADD's own plans. */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
