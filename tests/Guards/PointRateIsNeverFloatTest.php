<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Invariant 5 (docs/superpowers/specs/2026-09-12-add-point-a-foundation-design.md):
 * bcmath, never float, for anything touching the point rate. A float
 * division at an exact boundary (e.g. 1.80 / 0.10) can land just above the
 * intended integer and silently mischarge a member by a whole point — see
 * PointConversionService::usdToPointsCeil()'s docblock for the concrete
 * case. This guard scans app/ for the float casts and float arithmetic
 * that would reintroduce that bug outside the one place bcmath is already
 * used correctly.
 */
class PointRateIsNeverFloatTest extends TestCase
{
    use ScansSourceFiles;

    public function test_the_point_rate_is_never_cast_or_operated_on_as_a_float(): void
    {
        $violations = [];

        // A `(float)`/`(double)` cast directly on a `rate()` call, e.g.
        // `(float) $points->rate()`.
        $castOnRateCall = '/\((?:float|double)\)\s*[\$\w\->]*->rate\(\)/i';

        // A `(float)`/`(double)` cast adjacent to the raw setting key,
        // e.g. `(float) $settings->get('point.rate_usd')`.
        $castNearSetting = '/\((?:float|double)\)[^;]{0,80}point\.rate_usd/i';

        // floatval()/doubleval() wrapping a rate() call or the setting key.
        $floatValOnRate = '/(?:floatval|doubleval)\([^)]*(?:->rate\(\)|point\.rate_usd)[^)]*\)/i';

        // Float arithmetic operators (* / + -) directly adjacent to a
        // rate() call, on either side, e.g. `$points->rate() * $x` or
        // `$x * $points->rate()`. bcmath is required instead.
        $arithmeticAfterRate = '/->rate\(\)\s*[*\/+-]\s*/';
        $arithmeticBeforeRate = '/[*\/+-]\s*[\$\w]*->rate\(\)/';

        foreach ($this->phpFilesIn(app_path()) as $path => $contents) {
            if (str_contains($path, 'PointConversionService.php')) {
                continue;
            }

            if (
                preg_match($castOnRateCall, $contents)
                || preg_match($castNearSetting, $contents)
                || preg_match($floatValOnRate, $contents)
                || preg_match($arithmeticAfterRate, $contents)
                || preg_match($arithmeticBeforeRate, $contents)
            ) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations, "The point rate must never be cast to float or used in float arithmetic — use PointConversionService/bcmath instead:\n".implode("\n", $violations));
    }
}
