<?php

namespace Tests\Unit\Domain\Membership;

use App\Domain\Membership\Models\Plan;
use App\Domain\Settings\Services\SettingService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every billing outcome of the access-window feature derives from
 * windowOverlap(), so it is tested directly rather than only through the
 * checkout path.
 *
 * Times here are written in the app's local timezone (Asia/Damascus) and
 * converted to UTC, because that is how they arrive in production: the
 * session columns are UTC and the window is local wall-clock.
 */
class PlanAccessWindowTest extends TestCase
{
    use RefreshDatabase;

    private function localToUtc(string $time): Carbon
    {
        $timezone = app(SettingService::class)->get('app.timezone', 'Asia/Damascus');

        return Carbon::parse($time, $timezone)->setTimezone('UTC');
    }

    public function test_a_plan_without_a_window_reports_no_window_and_no_overlap(): void
    {
        $plan = Plan::factory()->create();

        $this->assertFalse($plan->hasAccessWindow());
        $this->assertSame(0, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 21:00'),
            $this->localToUtc('2026-09-24 23:00'),
        ));
    }

    public function test_a_half_configured_window_is_not_a_window(): void
    {
        $plan = Plan::factory()->create(['access_window_start' => '20:00']);

        $this->assertFalse($plan->hasAccessWindow());
    }

    public function test_a_session_entirely_inside_the_window_is_fully_covered(): void
    {
        $plan = Plan::factory()->evening()->create();

        $this->assertSame(2 * 3600, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 21:00'),
            $this->localToUtc('2026-09-24 23:00'),
        ));
    }

    public function test_a_session_entirely_outside_the_window_is_not_covered(): void
    {
        $plan = Plan::factory()->evening()->create();

        $this->assertSame(0, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 14:00'),
            $this->localToUtc('2026-09-24 16:00'),
        ));
    }

    /**
     * The case from the spec: a 19:00-22:00 session against a 20:00-00:00
     * plan is one paid hour plus two plan hours.
     */
    public function test_a_session_straddling_the_opening_boundary_is_split(): void
    {
        $plan = Plan::factory()->evening()->create();

        $this->assertSame(2 * 3600, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 19:00'),
            $this->localToUtc('2026-09-24 22:00'),
        ));
    }

    /**
     * The case the repo's lexical H:i comparison gets wrong: "23:30" <= "00:00"
     * is false as a string, so a naive implementation covers nothing here.
     */
    public function test_a_window_ending_at_midnight_covers_the_late_evening(): void
    {
        $plan = Plan::factory()->evening()->create();

        $this->assertSame(90 * 60, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 22:30'),
            $this->localToUtc('2026-09-25 00:00'),
        ));
    }

    public function test_a_session_running_past_midnight_stops_being_covered_at_the_window_end(): void
    {
        $plan = Plan::factory()->evening()->create();

        // 23:00 -> 01:00 is one hour inside (23:00-00:00) and one hour outside.
        $this->assertSame(3600, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 23:00'),
            $this->localToUtc('2026-09-25 01:00'),
        ));
    }

    public function test_a_window_that_wraps_past_midnight_covers_both_sides(): void
    {
        $plan = Plan::factory()->create([
            'access_window_start' => '22:00',
            'access_window_end' => '02:00',
        ]);

        // 21:00 -> 03:00 covers 22:00-02:00 = four hours.
        $this->assertSame(4 * 3600, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 21:00'),
            $this->localToUtc('2026-09-25 03:00'),
        ));
    }

    public function test_a_multi_day_session_accumulates_each_days_window(): void
    {
        $plan = Plan::factory()->evening()->create();

        // Two full evenings: 20:00-00:00 on the 24th and on the 25th.
        $this->assertSame(8 * 3600, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 12:00'),
            $this->localToUtc('2026-09-26 12:00'),
        ));
    }

    public function test_a_zero_length_or_inverted_range_covers_nothing(): void
    {
        $plan = Plan::factory()->evening()->create();

        $this->assertSame(0, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 21:00'),
            $this->localToUtc('2026-09-24 21:00'),
        ));

        $this->assertSame(0, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 23:00'),
            $this->localToUtc('2026-09-24 21:00'),
        ));
    }

    // ------------------------------------------------------------------
    // windowSegments(): the same window as a list of UTC intervals, so the
    // co-working seat pool can hold a seat only while the plan is open.
    // ------------------------------------------------------------------

    /**
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $segments
     * @return list<array{0: string, 1: string}>
     */
    private function local(array $segments): array
    {
        $timezone = app(SettingService::class)->get('app.timezone', 'Asia/Damascus');

        return array_map(fn (array $segment) => [
            $segment[0]->clone()->setTimezone($timezone)->format('Y-m-d H:i'),
            $segment[1]->clone()->setTimezone($timezone)->format('Y-m-d H:i'),
        ], $segments);
    }

    /** No window is open all day: the whole range is one segment. */
    public function test_a_plan_without_a_window_is_one_segment_over_the_whole_range(): void
    {
        $plan = Plan::factory()->create();

        $this->assertSame(
            [['2026-09-24 12:00', '2026-09-26 12:00']],
            $this->local($plan->windowSegments($this->localToUtc('2026-09-24 12:00'), $this->localToUtc('2026-09-26 12:00'))),
        );
    }

    public function test_each_day_contributes_its_window_clipped_to_the_range(): void
    {
        $plan = Plan::factory()->evening()->create();

        $this->assertSame(
            [
                ['2026-09-24 20:00', '2026-09-25 00:00'],
                ['2026-09-25 20:00', '2026-09-25 22:00'],
            ],
            $this->local($plan->windowSegments($this->localToUtc('2026-09-24 12:00'), $this->localToUtc('2026-09-25 22:00'))),
        );
    }

    /** 22:00–02:00 is one stretch across midnight, not two touching pieces. */
    public function test_a_wrapping_window_is_one_segment_across_midnight(): void
    {
        $plan = Plan::factory()->create([
            'access_window_start' => '22:00',
            'access_window_end' => '02:00',
        ]);

        $this->assertSame(
            [['2026-09-24 22:00', '2026-09-25 02:00']],
            $this->local($plan->windowSegments($this->localToUtc('2026-09-24 12:00'), $this->localToUtc('2026-09-25 12:00'))),
        );
    }

    public function test_the_segments_sum_to_the_overlap(): void
    {
        $plan = Plan::factory()->evening()->create();
        $from = $this->localToUtc('2026-09-24 12:00');
        $to = $this->localToUtc('2026-09-26 12:00');

        $sum = array_sum(array_map(
            fn (array $segment) => (int) $segment[0]->diffInSeconds($segment[1]),
            $plan->windowSegments($from, $to),
        ));

        $this->assertSame($plan->windowOverlap($from, $to), $sum);
    }

    public function test_a_malformed_window_has_no_segments(): void
    {
        $plan = Plan::factory()->create([
            'access_window_start' => '99:99',
            'access_window_end' => '00:00',
        ]);

        $this->assertSame([], $plan->windowSegments($this->localToUtc('2026-09-24 12:00'), $this->localToUtc('2026-09-25 12:00')));
    }

    public function test_a_malformed_window_covers_nothing_rather_than_throwing(): void
    {
        $plan = Plan::factory()->create([
            'access_window_start' => '99:99',
            'access_window_end' => '00:00',
        ]);

        $this->assertSame(0, $plan->windowOverlap(
            $this->localToUtc('2026-09-24 21:00'),
            $this->localToUtc('2026-09-24 23:00'),
        ));
    }
}
