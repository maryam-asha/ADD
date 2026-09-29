<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The member asks to end; reception confirms
 * (docs/decisions/coworking-self-service-sessions.md). Billing itself is
 * untouched: confirmation calls the same SessionClosureService::closeOut()
 * reception's own checkout uses.
 *
 * Lock order is session row first, end-request row second — the same order
 * SessionClosureService::autoClose() takes them in, so the two cannot
 * deadlock, and a closure that lands between a lookup and a write is
 * always seen.
 */
class SessionEndService
{
    public function __construct(
        private readonly CoworkingSessionService $sessions,
        private readonly SessionClosureService $closures,
    ) {}

    public function request(User $member): SessionEndRequest
    {
        return DB::transaction(function () use ($member) {
            // One pending request per session, even on a double tap.
            User::query()->whereKey($member->id)->lockForUpdate()->first();

            $found = $this->sessions->openSessionFor($member)
                ?? throw new ReceptionActionException('api.coworking.no_open_session');

            // A closure can land between the lookup above and the insert
            // below and leave an orphan pending request on a closed
            // session, so lock the row and re-check it is still open.
            $session = $found::query()->whereKey($found->getKey())->lockForUpdate()->firstOrFail();

            if ($session->checked_out_at !== null) {
                throw new ReceptionActionException('api.coworking.no_open_session');
            }

            return $session->pendingEndRequest()->first()
                ?? $session->endRequests()->create([
                    'user_id' => $member->id,
                    'requested_at' => now(),
                    'status' => SessionEndRequestStatus::Pending,
                ]);
        });
    }

    public function cancel(User $member): void
    {
        $session = $this->sessions->openSessionFor($member)
            ?? throw new ReceptionActionException('api.coworking.no_open_session');

        $pending = $session->pendingEndRequest()->first()
            ?? throw new ReceptionActionException('api.coworking.end_request_not_pending', 409);

        // Conditional on still being pending: a withdrawal racing a
        // confirmation must not turn `confirmed` into `cancelled`.
        $updated = SessionEndRequest::query()
            ->whereKey($pending->id)
            ->where('status', SessionEndRequestStatus::Pending->value)
            ->update([
                'status' => SessionEndRequestStatus::Cancelled->value,
                'resolved_at' => now(),
            ]);

        if ($updated === 0) {
            throw new ReceptionActionException('api.coworking.end_request_not_pending', 409);
        }
    }

    /**
     * $checkedOutAt defaults to the moment the member pressed. Marked
     * confirmed before closing, so the closure's "supersede whatever is still
     * pending" leaves this row alone; both happen in one transaction, so a
     * closure that refuses (past closing, before check-in) undoes the mark too.
     */
    public function confirm(SessionEndRequest $endRequest, User $operator, ?CarbonInterface $checkedOutAt = null): SessionEndRequest
    {
        // Read without a lock only to learn which session this belongs to;
        // the row that decides anything is re-read under lock below.
        $session = $endRequest->sessionable;

        return DB::transaction(function () use ($endRequest, $operator, $checkedOutAt, $session) {
            $lockedSession = $session::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();

            $locked = SessionEndRequest::query()->whereKey($endRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SessionEndRequestStatus::Pending) {
                throw new ReceptionActionException('api.coworking.end_request_not_pending', 409);
            }

            $time = $checkedOutAt ?? $locked->requested_at;

            $locked->forceFill([
                'status' => SessionEndRequestStatus::Confirmed,
                'resolved_by_user_id' => $operator->id,
                'resolved_at' => now(),
                'checked_out_at' => $time->copy()->setTimezone('UTC'),
            ])->save();

            $this->closures->closeOut($lockedSession, $time);

            return $locked;
        });
    }
}
