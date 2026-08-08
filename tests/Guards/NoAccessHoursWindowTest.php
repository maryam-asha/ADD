<?php

namespace Tests\Guards;

use App\Services\Access\AccessHoursPolicy;
use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * PRD decision #11: access is 24/7; the previous 08:00-23:00 window is
 * "ملغاة نهائياً من كل موضع" (abolished finally, everywhere). This was a real
 * implemented feature (config/access.php + AccessHoursPolicy), not a stub —
 * so the risk is a partial revert or a copy-pasted reintroduction in a
 * later booking/check-in flow. The guard fails on the file paths and on
 * the specific config key, not just "the class doesn't exist", so it also
 * catches a re-implementation under a different name.
 */
class NoAccessHoursWindowTest extends TestCase
{
    use ScansSourceFiles;

    public function test_no_access_hours_config_file_exists(): void
    {
        $this->assertFileDoesNotExist(config_path('access.php'));
    }

    public function test_no_access_hours_policy_class_exists(): void
    {
        $this->assertFalse(
            class_exists(AccessHoursPolicy::class),
            'Decision #11 abolished the allowed-hours window; AccessHoursPolicy must not exist.'
        );
    }

    public function test_no_source_file_references_an_allowed_hours_window(): void
    {
        $violations = [];

        foreach (['app', 'config', 'database', 'routes'] as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path => $contents) {
                if (preg_match('/allowed_hours|ACCESS_HOURS_(START|END)|isWithinAllowedHours/i', $contents)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, "Decision #11 abolished the access-hours window everywhere:\n".implode("\n", $violations));
    }
}
