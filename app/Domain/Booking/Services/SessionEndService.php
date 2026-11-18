<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Events\CoworkingSessionEnded;
use App\Domain\Booking\Events\SessionEndRequestCancelled;
use App\Domain\Booking\Events\SessionEndRequestCreated;
use App\Domain\Booking\Events\SessionEndRequestResolved;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Identity\Models\User;
use App\Services\Realtime\RealtimeBroadcaster;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The member asks to end; reception confirms
 * (docs/decisions/coworking-self-service-sessions.md). Billing itself is
 * untouched: confirmation calls the same SessionClosureService::closeOut()
 * reception's own checkout uses.
 *
 * Paying comes after, and is not this service's: confirming closes and
 * prices the session, nothing more. The member is then shown the payment
 * methods and pays through POST member/coworking-sessions/{type}/{id}/pay —
 * points spent at once, a Paymera link, or cash reception collects.
 *
 * Lock order is session row first, end-request row second — the same order
 * SessionClosureService::autoClose() takes them in, so the two cannot
 * deadlock, and a closure that lands between a lookup and a write is
 * always seen.
 *
 * Each step tells the desk (and, on confirmation, the member) in real time
 * once its transaction commits — RealtimeBroadcaster; the FCM push the
 * controller sends on confirmation is unchanged.
 */
class SessionEndService
{
    public function __construct(
        private readonly CoworkingSessionService $sessions,
        private readonly SessionClosureService $closures,
        private readonly CheckoutTokenService $checkouts,
        private readonly RealtimeBroadcaster $realtime,
    ) {}

    /**
     * `$checkoutToken` is the checkout the member was shown
     * (CheckoutTokenService), whose time and price the request keeps, so
     * reception closes the session at exactly what the member agreed to.
     * Pressing again, after a fresh checkout, updates the waiting request.
     */
    public function request(User $member, string $checkoutToken): SessionEndRequest
    {
        return DB::transaction(function () use ($member, $checkoutToken) {
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

            $quote = $this->checkouts->redeem($checkoutToken, $member, $session);

            $pending = $session->pendingEndRequest()->first();

            if ($pending !== null) {
                $pending->forceFill([
                    'requested_at' => $quote->at,
                    'quoted_points' => $quote->points,
                ])->save();

                // Changed, not new — still the desk's cue to reload it.
                $this->realtime->send(new SessionEndRequestCreated($pending->setRelation('sessionable', $session)));

                return $pending;
            }

            $created = $session->endRequests()->create([
                'user_id' => $member->id,
                'requested_at' => $quote->at,
                'quoted_points' => $quote->points,
                'status' => SessionEndRequestStatus::Pending,
            ]);

            $this->realtime->send(new SessionEndRequestCreated($created->setRelation('sessionable', $session)));

            return $created;
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

        $this->realtime->send(new SessionEndRequestCancelled($pending->setRelation('sessionable', $session)));
    }

    /**
     * $checkedOutAt defaults to the moment the member pressed. A request
     * made from a checkout closes at the time that checkout priced, and no
     * other: reception confirms the member left, it does not move the time
     * the member was shown a price for. Marked confirmed before closing, so
     * the closure's "supersede whatever is still pending" leaves this row
     * alone; both happen in one transaction, so a closure that refuses (past
     * closing, before check-in) undoes the mark too.
     *
     * Nothing is paid here: a session still owed is paid by the member
     * afterwards, by the method they choose then.
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

            if ($locked->quoted_points !== null && $checkedOutAt !== null) {
                throw new ReceptionActionException('api.coworking.end_time_locked');
            }

            $time = $checkedOutAt ?? $locked->requested_at;

            $locked->forceFill([
                'status' => SessionEndRequestStatus::Confirmed,
                'resolved_by_user_id' => $operator->id,
                'resolved_at' => now(),
                'checked_out_at' => $time->copy()->setTimezone('UTC'),
            ])->save();

            $this->closures->closeOut($lockedSession, $time);

            $locked->setRelation('sessionable', $lockedSession);

            // Only if the closure above commits: a refused one is not news.
            $this->realtime->send(
                new CoworkingSessionEnded($locked),
                new SessionEndRequestResolved($locked),
            );

            return $locked;
        });
    }
}
