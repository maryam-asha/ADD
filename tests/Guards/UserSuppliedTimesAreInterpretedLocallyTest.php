<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * A wall clock typed by a person is not an instant until someone says which
 * timezone it belongs to.
 *
 * `config('app.timezone')` is UTC while the district runs on the
 * `app.timezone` Setting (Asia/Damascus) — three hours apart. So
 * `Carbon::parse($request->validated('start_at'))` reads 19:00 as 19:00 UTC,
 * and anything that then compares it as local sees 22:00. That shipped twice:
 * events landed on the wrong calendar day, and a 19:00 booking in a space
 * open until 20:00 was refused as outside business hours.
 *
 * The fix in both cases was to interpret the value at the edge, in the Form
 * Request, via App\Http\Requests\Concerns\InterpretsLocalTimes. This guard
 * keeps the next endpoint from re-introducing the bug: a controller must not
 * parse a user-supplied time itself.
 *
 * See docs/decisions/event-approval-and-custom-forms.md.
 */
class UserSuppliedTimesAreInterpretedLocallyTest extends TestCase
{
    use ScansSourceFiles;

    /**
     * A request value parsed with no second argument. The timezone it lands
     * in is then whatever config('app.timezone') happens to be — exactly the
     * assumption behind both bugs above. Stating it explicitly,
     * `parse($value, 'UTC')` after the Form Request has normalised it, is
     * both correct and readable.
     */
    private const NAIVE_PARSE = '/Carbon(?:Immutable)?::parse\(\s*\$(?:request|this)->[A-Za-z_]+\([^()]*\)\s*\)/';

    public function test_no_controller_parses_a_user_supplied_time_without_a_timezone(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path('Http/Controllers')) as $path => $contents) {
            if (preg_match_all(self::NAIVE_PARSE, $contents, $matches)) {
                foreach ($matches[0] as $match) {
                    $violations[] = "{$path}: {$match}";
                }
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['A user-supplied time must be interpreted before it is parsed.'],
            ['Use the InterpretsLocalTimes trait in the Form Request instead:'],
            ['    protected function prepareForValidation(): void'],
            ['    {'],
            ["        \$this->interpretLocalTimes(['start_at', 'end_at']);"],
            ['    }'],
            [''],
            $violations,
        )));
    }

    /**
     * Every Form Request that accepts a date the user types has to say what
     * timezone it is in — either by interpreting it, or by being one of the
     * cases below where the value is never compared as a wall clock.
     *
     * @var list<string> paths whose date fields are exempt, with why
     */
    private const NOT_WALL_CLOCK = [
        // A filter bound, compared against stored instants, never rendered
        // as a time of day.
        'app/Http/Requests/Admin/StoreExchangeRateRequest.php',
        // Client crash telemetry: the device's own clock, already an instant.
        'app/Http/Requests/Mobile/StoreErrorLogRequest.php',
        // A settings value, not a moment.
        'app/Http/Requests/Admin/UpdateSettingRequest.php',
        // Business hours are stored as times of day (H:i) against a weekday,
        // never as instants, so there is nothing to convert.
        'app/Http/Requests/Admin/StoreBusinessHourRequest.php',
        'app/Http/Requests/Admin/StoreBusinessHourExceptionRequest.php',
        // Maintenance windows: stored, but nothing reads them yet. Revisit
        // with whatever service starts comparing them.
        'app/Http/Requests/Admin/UpdateSpaceStatusRequest.php',
        'app/Http/Requests/Admin/UpdateResourceStatusRequest.php',
        // An expiry instant on a points allocation; compared to now(), never
        // to a time of day.
        'app/Http/Requests/Member/StoreWalletAllocationRequest.php',
    ];

    public function test_form_requests_taking_a_typed_date_interpret_it_or_are_listed_as_exempt(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path('Http/Requests')) as $path => $contents) {
            if (in_array($path, self::NOT_WALL_CLOCK, true)) {
                continue;
            }

            // Fields validated as a date, i.e. a moment rather than a
            // free-form string.
            if (! preg_match("/=>\s*\[[^\]]*'date'/", $contents)) {
                continue;
            }

            if (! str_contains($contents, 'interpretLocalTimes')) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations, implode("\n", array_merge(
            ['These Form Requests accept a date the user types but never say'],
            ['what timezone it is in. Either use the InterpretsLocalTimes'],
            ['trait, or add the file to NOT_WALL_CLOCK with a reason:'],
            [''],
            $violations,
        )));
    }
}
