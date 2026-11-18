<?php

namespace Tests\Guards;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PRD decision #18: `community_members` carries no foreign key to `users`
 * — deliberate, not an oversight (belonging to ADD's ecosystem never
 * requires a contract). Extended here now that `users`/`companies` are
 * real tables (Phase 2), so the temptation to "just wire them together"
 * has an actual guard, not just a comment.
 *
 * `add_members.linked_user_id` (D.12) is a distinct, still-open question —
 * whether a *verified* ADD Member may optionally link to a login account —
 * and stays undecided. This guard is about `community_members` itself,
 * the base of the membership ladder, which decision #18 already settles.
 */
class CommunityMembersNoUserLinkTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_COLUMNS = ['user_id', 'linked_user_id'];

    public function test_community_members_has_no_column_linking_to_users(): void
    {
        $violations = [];

        foreach (self::FORBIDDEN_COLUMNS as $column) {
            if (Schema::hasColumn('community_members', $column)) {
                $violations[] = "community_members.{$column}";
            }
        }

        $this->assertSame([], $violations, "Decision #18 — community_members carries no FK to users:\n".implode("\n", $violations));
    }
}
