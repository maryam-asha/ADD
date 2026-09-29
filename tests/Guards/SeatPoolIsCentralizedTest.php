<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * A branch's co-working seats are counted in one place.
 *
 * Seats open (co_spaces, rooms lent to co-working) and seats taken
 * (subscriptions holding theirs, bookings, walk-ins) are gathered by
 * CoworkingPoolService alone and summed by PoolTimeline. A second place
 * assembling its own timeline would count a subscriber twice, or forget the
 * walk-ins, and a booking, a walk-in and a subscription sale would stop
 * agreeing about whether a seat is free
 * (docs/decisions/coworking-seat-pool.md).
 */
class SeatPoolIsCentralizedTest extends TestCase
{
    use ScansSourceFiles;

    public function test_only_the_pool_service_builds_a_pool_timeline(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path()) as $path => $contents) {
            if ($path === 'app/Domain/Booking/Services/CoworkingPoolService.php') {
                continue;
            }

            if (str_contains($contents, 'PoolTimeline::build(')) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'Only CoworkingPoolService gathers the co-working pool; ask it for a timeline.');
    }

    public function test_the_pool_service_still_owns_the_pool(): void
    {
        $service = file_get_contents(app_path('Domain/Booking/Services/CoworkingPoolService.php'));

        $this->assertStringContainsString('PoolTimeline::build(', $service);

        foreach (['timeline', 'heldStretches', 'coveringMemberships', 'heldMemberships'] as $method) {
            $this->assertStringContainsString("function {$method}(", $service);
        }
    }
}
