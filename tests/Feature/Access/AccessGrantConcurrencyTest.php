<?php

namespace Tests\Feature\Access;

use App\Domain\Access\Enums\AccessGrantStatus;
use App\Domain\Access\Exceptions\LockAccessDeniedException;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Access\Services\PasscodeIssuanceService;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AccessGrantConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A scheduled RevokeAccessGrantsOnMaintenance run and a reception
     * kiosk's activation request can both touch the same grant row.
     * Injects the race at the exact moment activate()'s own transaction
     * begins — after nothing has locked the row yet — mutating it to
     * `revoked` right before activate()'s lockForUpdate() query runs.
     * Not a claim of real multi-connection concurrency: this suite's
     * in-memory SQLite can't reproduce that (same documented limitation
     * as WalkInCapacityServiceTest.php and the sp-today race fix in
     * 1e7026b) — a direct, honest injection at the one point that
     * matters: does activate()'s lock-and-recheck catch a status change
     * that happened after the initial read, rather than trusting stale
     * state?
     *
     * Deliberately does not re-assert the row's persisted status after
     * the call, unlike a naive version of this test might: the injected
     * UPDATE runs on the same connection as activate()'s own nested
     * DB::transaction(), inside the SAVEPOINT that transaction opens, so
     * when activate() rolls that savepoint back on throwing, the raced
     * UPDATE is unwound along with it — a same-connection simulation
     * artifact, not something a real second connection's already-committed
     * write would be subject to. The precedent this pattern is mirrored
     * from (ExchangeRateControllerTest::test_a_suggestion_accepted_between_validation_and_the_lock_is_rejected_with_no_new_row)
     * has the same shape and, for the same reason, only asserts the
     * listener fired and the operation was rejected — not the
     * post-rollback DB value. What this test does prove: the exception is
     * thrown before activate()'s forceFill(['status' => Activated])->save()
     * line is ever reached, so the race can't be silently activated.
     */
    public function test_activation_racing_a_maintenance_revoke_is_rejected_not_silently_activated(): void
    {
        $grant = AccessGrant::factory()->create(['status' => AccessGrantStatus::Issued]);

        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired, $grant) {
            if (! $fired) {
                $fired = true;
                DB::table('access_grants')->where('id', $grant->id)->update(['status' => 'revoked']);
            }
        });

        $this->expectException(LockAccessDeniedException::class);

        try {
            app(PasscodeIssuanceService::class)->activate($grant);
        } finally {
            $this->assertTrue($fired, 'The race-injection listener never fired — this test would pass vacuously without it.');
        }
    }
}
