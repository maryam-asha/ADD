<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * PRD decision #17: Experience and Ecosystem are structurally separate
 * layers in the data model, not two sections of one CMS. Decision #18: an
 * Ecosystem member belongs to ADD without any contractual relationship to
 * Core. This test is the executable form of that separation — the PRD's
 * own §9 principle is that a written rule with no automated guard is a
 * request, not a guarantee.
 *
 * Booking, Access, Membership and Finance are Core: a contractual, paid
 * relationship (PRD §1) and the money/exchange-rate logic underpinning it.
 * Neither Experience nor Ecosystem may depend on them, and they may not
 * depend on each other. This list is written against domains that do not
 * all exist yet (Booking/Access/Membership land in later phases) on
 * purpose, so the guard is already in force the moment those domains are
 * created — nobody has to remember to extend it.
 *
 * The Ecosystem<->Experience ban has one deliberate exception: ERD v2.0 §7-8
 * keeps `event_attendees.event_id -> events` as a documented cross-domain
 * FK — an anonymous public RSVP (Ecosystem) against an internal event
 * (Experience) — distinct from the identity-verified `event_registrations`
 * that Experience uses internally. Every other file stays banned; a new
 * exception needs its own ERD citation, not a wider carve-out.
 */
class DomainLayerBoundaryTest extends TestCase
{
    use ScansSourceFiles;

    /** @var array<string, list<string>> domain => domains it may not import from */
    private const FORBIDDEN = [
        'Ecosystem' => ['Experience', 'Booking', 'Access', 'Membership', 'Finance'],
        'Experience' => ['Ecosystem', 'Booking', 'Access', 'Membership', 'Finance'],
    ];

    /** @var array<string, list<string>> file => domains it is cleared to import from, with why above */
    private const ALLOWLIST = [
        'app/Domain/Ecosystem/Models/EventAttendee.php' => ['Experience'],
        'app/Domain/Experience/Models/Event.php' => ['Ecosystem'],
    ];

    public function test_ecosystem_and_experience_do_not_depend_on_core_or_each_other(): void
    {
        $violations = [];

        foreach (self::FORBIDDEN as $domain => $forbidden) {
            $dir = app_path("Domain/{$domain}");

            foreach ($this->phpFilesIn($dir) as $path => $contents) {
                $cleared = self::ALLOWLIST[$path] ?? [];

                foreach ($forbidden as $forbiddenDomain) {
                    if (in_array($forbiddenDomain, $cleared, true)) {
                        continue;
                    }

                    if (preg_match('/use\s+App\\\\Domain\\\\'.$forbiddenDomain.'\\\\/', $contents)) {
                        $violations[] = "{$path} (Domain\\{$domain}) imports from Domain\\{$forbiddenDomain}";
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Decision #17/#18 — Experience/Ecosystem must not depend on Core or each other:\n".implode("\n", $violations));
    }
}
