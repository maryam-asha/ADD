<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * The booking availability rules exist exactly once, in AvailabilityService.
 *
 * Before the availability layer, every rule lived as a private assertion on
 * BookingCreationService, and the overlap predicate had already been copied
 * a second time into BookingExtensionService. Adding a calendar meant a
 * third copy — and the moment two copies exist, the grid the member is
 * shown and the POST that refuses them can disagree, silently, forever, in
 * whichever direction the copies drift.
 *
 * Centralising them was the fix; this guard is what makes it permanent
 * rather than a convention someone unknowingly reverts under deadline. It
 * greps for the *shape* of each rule, so pasting the predicate back into a
 * service fails the build even though the code would run correctly.
 *
 * See docs/decisions/booking-availability-and-calendar.md.
 */
class AvailabilityRulesAreCentralizedTest extends TestCase
{
    use ScansSourceFiles;

    /**
     * Files allowed to contain these predicates.
     *
     * AvailabilityService owns them. The model scopes are the query
     * builders the service composes — a booking's window and a room's
     * co-working stretch — and are themselves the shared definition rather
     * than a copy of it.
     */
    private const ALLOWLIST = [
        'app/Domain/Booking/Services/AvailabilityService.php',
        'app/Domain/Booking/Models/Booking.php',
        'app/Domain/Booking/Models/SpaceCoworkingWindow.php',
    ];

    /**
     * Scanned for booking-rule duplication: the Booking domain itself, plus
     * every surface that acts on bookings.
     *
     * Deliberately not the whole of app/ — `where('start_at', '<', $now)`
     * on `events` asks whether an event has started, which is a different
     * question about a different table that happens to share a column name.
     * DomainLayerBoundaryTest already forbids Experience from importing
     * Booking at all, so it cannot reach these rules to duplicate them.
     *
     * @return array<string, string>
     */
    private function offendingFiles(string $pattern): array
    {
        $scanned = [
            ...$this->phpFilesIn(app_path('Domain/Booking')),
            ...$this->phpFilesIn(app_path('Http/Controllers/Api/V1/Member')),
            ...$this->phpFilesIn(app_path('Http/Controllers/Api/V1/Admin')),
            ...$this->phpFilesIn(app_path('Console/Commands')),
        ];

        $offenders = [];

        foreach ($scanned as $path => $contents) {
            if (in_array($path, self::ALLOWLIST, true)) {
                continue;
            }

            if (preg_match($pattern, $contents) === 1) {
                $offenders[$path] = $contents;
            }
        }

        return $offenders;
    }

    public function test_the_overlap_predicate_appears_only_in_the_availability_service(): void
    {
        // The half-open interval intersection: `start_at <` compared against
        // a window's end, or `end_at >` against its start. Deliberately not
        // matching a bare liveness filter such as `end_at > now()`, which
        // asks whether a booking is still running rather than whether two
        // windows collide.
        $offenders = $this->offendingFiles(
            "/where\(\s*'start_at'\s*,\s*'<'|where\(\s*'end_at'\s*,\s*'>'\s*,\s*\\\$(?!now\b)/"
        );

        $this->assertSame(
            [],
            array_keys($offenders),
            'The booking overlap predicate must live only in AvailabilityService (or the Booking scopes it composes). '
            .'A second copy lets the availability calendar and BookingCreationService disagree about whether a slot is free.'
        );
    }

    /**
     * The same, for a room lent to co-working: whether a stretch touches a
     * window is SpaceCoworkingWindow::scopeOverlapping() and
     * AvailabilityService::isInCoworkingMode(), nowhere else.
     */
    public function test_the_co_working_window_overlap_appears_only_in_the_availability_service(): void
    {
        $offenders = $this->offendingFiles(
            "/where\(\s*'starts_at'\s*,\s*'<'|where\(\s*'ends_at'\s*,\s*'>'\s*,\s*\\\$(?!now\b)/"
        );

        $this->assertSame(
            [],
            array_keys($offenders),
            'Whether a stretch meets a room\'s co-working window is decided by SpaceCoworkingWindow::scopeOverlapping() '
            .'and AvailabilityService alone.'
        );
    }

    public function test_the_blocking_status_set_appears_only_in_the_availability_service(): void
    {
        // whereIn('status', [Confirmed, Pending]) — which statuses hold a slot.
        $offenders = $this->offendingFiles("/whereIn\(\s*'status'\s*,\s*\[\s*BookingStatus::/");

        $this->assertSame(
            [],
            array_keys($offenders),
            'Which booking statuses block a slot is decided by Booking::scopeBlocking() alone.'
        );
    }

    public function test_the_granularity_modulo_appears_only_in_the_availability_service(): void
    {
        $offenders = $this->offendingFiles('/%\s*\$granularity\b/');

        $this->assertSame(
            [],
            array_keys($offenders),
            'Slot-granularity arithmetic must live only in AvailabilityService, so the slots the calendar '
            .'offers are exactly the start times BookingCreationService accepts.'
        );
    }

    public function test_the_availability_service_still_owns_every_rule(): void
    {
        $service = file_get_contents(app_path('Domain/Booking/Services/AvailabilityService.php'));

        foreach ([
            'isWithinBusinessHours',
            'isValidGranularity',
            'isLongEnough',
            'matchesDurationGranularity',
            'isFarEnoughAhead',
            'isWithinAdvanceHorizon',
            'overlapCount',
            'exceedsCeiling',
            'violatesBuffer',
            // The co-working seat pool (docs/decisions/coworking-seat-pool.md).
            'usesBranchPool',
            'exceedsPool',
            'isInCoworkingMode',
        ] as $predicate) {
            $this->assertStringContainsString(
                "function {$predicate}(",
                $service,
                "AvailabilityService must expose {$predicate}() — it is the single definition both the read "
                .'and write paths depend on.'
            );
        }
    }
}
