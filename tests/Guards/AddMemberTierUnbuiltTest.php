<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * The 2026-08-17 profile-completion phase investigated whether an "ADD
 * Member" tier/promotion mechanism already existed to wire the completion
 * score into — the spec's §3 investigation, recorded in
 * docs/decisions/profile-fields-completion-score-contact-links.md. It
 * doesn't: `add_members` is named in the backend build plan and
 * D.12 as a future rung of the Community → ADD Members → ADD Club ladder,
 * but no table, model, or promotion logic exists in code. Per that
 * decision, this phase reports the gap rather than inventing a tier system
 * as a side effect — this guard (mirroring AddClubUnmodeledTest for the
 * tier above this one) keeps it that way until someone deliberately
 * removes it alongside building the real thing.
 */
class AddMemberTierUnbuiltTest extends TestCase
{
    use ScansSourceFiles;

    public function test_no_table_model_or_route_models_the_add_member_tier(): void
    {
        $violations = [];

        foreach (['app/Domain', 'database/migrations', 'routes'] as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path => $contents) {
                if (preg_match('/\badd_members\b/i', $contents) || preg_match('/\bAddMember\b/', $contents)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, "add_members / AddMember stays unbuilt until the membership-ladder decision is made:\n".implode("\n", $violations));
    }
}
