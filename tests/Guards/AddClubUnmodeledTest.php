<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * PRD §7.2: the invite mechanism for ADD Club and how its lifecycle relates
 * to a Core contract are both deferred by explicit decision — "لا يُبنى أي
 * منطق في النظام يفترض إجابة لأيٍّ من هذين السؤالين قبل حسمهما" (no logic in
 * the system may presume an answer to either question before it is
 * settled). Unlike the other §7.3 open items, which get a documented
 * neutral placeholder, ADD Club gets nothing at all — no table, column, or
 * enum value. This guard keeps it that way until someone deliberately
 * removes this test alongside the decision being made.
 */
class AddClubUnmodeledTest extends TestCase
{
    use ScansSourceFiles;

    public function test_no_table_column_or_enum_value_models_add_club(): void
    {
        $violations = [];

        foreach (['app/Domain', 'database/migrations'] as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path => $contents) {
                if (preg_match('/\bclub\b/i', $contents)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, "PRD §7.2 — ADD Club stays unmodeled until its invite/lifecycle decisions are settled:\n".implode("\n", $violations));
    }
}
