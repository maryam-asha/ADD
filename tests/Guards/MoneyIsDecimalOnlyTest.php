<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * PRD decision #15: "كل القيم المالية من نوع DECIMAL حصراً — لا FLOAT ولا
 * DOUBLE في أي موضع" (every monetary value is DECIMAL exclusively — no FLOAT
 * or DOUBLE anywhere). Binary floating point cannot represent currency
 * amounts exactly; this guard makes the rule fail a build instead of
 * relying on every future migration author remembering it.
 */
class MoneyIsDecimalOnlyTest extends TestCase
{
    use ScansSourceFiles;

    public function test_no_migration_declares_a_float_or_double_column(): void
    {
        $violations = [];

        foreach ($this->phpFilesIn(database_path('migrations')) as $path => $contents) {
            if (preg_match('/->\s*(float|double)\s*\(/i', $contents, $match)) {
                $violations[] = "{$path} uses ->{$match[1]}(...)";
            }
        }

        $this->assertSame([], $violations, "Decision #15 forbids FLOAT/DOUBLE for any value:\n".implode("\n", $violations));
    }
}
