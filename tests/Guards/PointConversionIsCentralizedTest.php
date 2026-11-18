<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * Invariant 4 (docs/superpowers/specs/2026-09-12-add-point-system-design.md):
 * PointConversionService is the only place that reads the point rate.
 * Any other file doing so is duplicating rounding rules that must exist
 * exactly once.
 */
class PointConversionIsCentralizedTest extends TestCase
{
    use ScansSourceFiles;

    public function test_only_the_conversion_service_reads_the_point_rate(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(app_path()) as $path => $contents) {
            if (str_contains($path, 'PointConversionService.php')) {
                continue;
            }

            if (str_contains($contents, 'point.rate_usd')) {
                $violations[] = $path;
            }
        }

        $this->assertSame([], $violations, "Only PointConversionService may read point.rate_usd:\n".implode("\n", $violations));
    }
}
