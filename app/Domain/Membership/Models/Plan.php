<?php

namespace App\Domain\Membership\Models;

use App\Concerns\HasTranslations;
use App\Domain\Settings\Services\SettingService;
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
        'duration_days',
        'included_hours',
        'access_window_start',
        'access_window_end',
        'is_active',
        'is_public',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'subtitle' => 'array',
            'is_subscription' => 'boolean',
            'price_points' => 'integer',
            'included_hours' => 'decimal:2',
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

        $timezone = app(SettingService::class)->get('app.timezone', 'Asia/Damascus');

        $start = $from->clone()->setTimezone($timezone);
        $end = $to->clone()->setTimezone($timezone);

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $windowStart = $this->minutesSinceMidnight($this->access_window_start);
        $windowEnd = $this->minutesSinceMidnight($this->access_window_end);

        if ($windowStart === null || $windowEnd === null) {
            return 0;
        }

        // Each day contributes at most two segments: the wrap case splits the
        // window into a tail on the previous day and a head on this one.
        $segments = $windowEnd > $windowStart
            ? [[$windowStart, $windowEnd]]
            : [[$windowStart, self::MINUTES_PER_DAY], [0, $windowEnd]];

        $seconds = 0;

        // Walk local calendar days, not 24-hour blocks: DST would make the
        // latter drift off midnight. Start a day early so a wrapped window's
        // tail from the previous day is counted too.
        $cursor = $start->clone()->startOfDay()->subDay();
        $limit = $end->clone()->startOfDay();

        while ($cursor->lessThanOrEqualTo($limit)) {
            foreach ($segments as [$segmentStart, $segmentEnd]) {
                $seconds += $this->overlapSeconds(
                    $start,
                    $end,
                    $cursor->clone()->addMinutes($segmentStart),
                    $cursor->clone()->addMinutes($segmentEnd),
                );
            }

            $cursor->addDay();
        }

        return $seconds;
    }

    private function overlapSeconds(
        CarbonInterface $start,
        CarbonInterface $end,
        CarbonInterface $segmentStart,
        CarbonInterface $segmentEnd
    ): int {
        $from = $start->greaterThan($segmentStart) ? $start : $segmentStart;
        $to = $end->lessThan($segmentEnd) ? $end : $segmentEnd;

        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        return (int) $from->diffInSeconds($to);
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
}
