<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Events\CoworkingSessionEnded;
use App\Domain\Booking\Events\SessionEndRequestCancelled;
use App\Domain\Booking\Events\SessionEndRequestCreated;
use App\Domain\Booking\Events\SessionEndRequestResolved;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\SessionEndRequest;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Services\Realtime\RealtimeBroadcaster;
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
        private readonly PaymentService $payments,
        private readonly CheckoutTokenService $checkouts,
        private readonly RealtimeBroadcaster $realtime,
    ) {}

    /**
     * `$paymentMethod` is how the member will pay (a config/payments.php
     * key); `$checkoutToken` is the checkout they were shown
     * (CheckoutTokenService), whose time and price the request keeps, so
     * reception closes the session at exactly what the member agreed to.
     * Pressing again, after a fresh checkout or with another method,
     * updates the waiting request.
     */
    public function request(User $member, string $paymentMethod, string $checkoutToken): SessionEndRequest
    {
        return DB::transaction(function () use ($member, $paymentMethod, $checkoutToken) {
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
                    'payment_method' => $paymentMethod,
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
                'payment_method' => $paymentMethod,
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
     */
    /**
     * Closing the session settles it the way the member chose
     * (settleAsChosen()); `$cashCollected` is the desk saying it has the
     * money for a `cash` request.
     */
    public function confirm(SessionEndRequest $endRequest, User $operator, ?CarbonInterface $checkedOutAt = null, bool $cashCollected = false): SessionEndRequest
    {
        // Read without a lock only to learn which session this belongs to;
        // the row that decides anything is re-read under lock below.
        $session = $endRequest->sessionable;

        return DB::transaction(function () use ($endRequest, $operator, $checkedOutAt, $session, $cashCollected) {
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

            $this->settleAsChosen($lockedSession, $locked->payment_method, $cashCollected, $operator);

            $locked->setRelation('sessionable', $lockedSession);

            // Only if the closure above commits: a refused one is not news.
            $this->realtime->send(
                new CoworkingSessionEnded($locked),
                new SessionEndRequestResolved($locked),
            );

            return $locked;
        });
    }

    /**
     * Once closed, the session has its price, and is paid as the member
     * asked: from their points, or in cash the desk says it holds. A wallet
     * that no longer covers it leaves the session unpaid — closing is not
     * undone for it — and a card is paid by the member in the app through
     * POST member/coworking-sessions/{type}/{id}/pay. Anything already paid
     * (a booking paid when made, a plan-covered walk-in) is left alone.
     */
    private function settleAsChosen(Booking|WalkinSession $session, ?string $method, bool $cashCollected, User $operator): void
    {
        if ($session->payment_state === PaymentState::Paid || (int) $session->price_points <= 0) {
            return;
        }

        if ($method === 'cash' && $cashCollected) {
            $this->closures->settlePayment($session, PaymentMethod::Cash, $operator);

            return;
        }

        if ($method !== WalletMethod::KEY) {
            return;
        }

        try {
            // Its own savepoint: a refused debit must not take the closure
            // down with it.
            DB::transaction(fn () => $this->payments->start(
                $session instanceof Booking ? PaymentPurpose::Booking : PaymentPurpose::CoworkingSession,
                $session->user,
                WalletMethod::KEY,
                ['payable' => $session, 'wallet_owner_type' => OwnerType::User, 'wallet_owner_id' => $session->user_id],
            ));
        } catch (InsufficientPointsException|ReceptionActionException) {
            // Short, or no wallet: owed, and the desk sees it as such.
        }

        $session->refresh();
    }
}
