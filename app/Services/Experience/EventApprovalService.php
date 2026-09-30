<?php

namespace App\Services\Experience;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Services\WalletService;
use App\Services\Experience\Exceptions\EventActionException;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use Illuminate\Support\Facades\DB;

/**
 * Approving and rejecting registrations for events that ask for review.
 *
 * Lives outside app/Domain/ because it needs WalletService, and
 * DomainLayerBoundaryTest bans Domain\Experience from importing
 * Domain\Membership — the same reason EventRegistrationService sits here.
 *
 * Points are taken at approval, never at submission, so a pending
 * registration costs the member nothing and a rejection has nothing to
 * refund.
 */
class EventApprovalService
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * Admit a pending registration, taking payment if the event is paid.
     *
     * A failure anywhere here — the event filled up, the member spent
     * their points in the meantime — aborts the transaction, so the
     * registration is left pending and the wallet untouched rather than
     * half-approved.
     */
    public function approve(EventAttendee $attendee, User $operator): void
    {
        DB::transaction(function () use ($attendee, $operator) {
            $locked = $this->lockPending($attendee);

            $event = Event::query()
                ->whereKey($locked->event_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertSeatAvailable($event, $locked);

            // Approved first: only an approved registration is paid for. A
            // charge that fails rolls the approval back with it.
            $locked->forceFill([
                'status' => EventRegistrationStatus::Approved,
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ])->save();

            $this->chargeIfPaid($event, $locked);

            $this->notify($locked->user_id, 'event.registration_approved');
        });
    }

    /**
     * Turn a pending registration down. Nothing was charged, so nothing is
     * refunded; the seat is freed by the status alone.
     */
    public function reject(EventAttendee $attendee, User $operator, string $reason): void
    {
        if (trim($reason) === '') {
            throw new EventActionException('api.event.rejection_reason_required');
        }

        DB::transaction(function () use ($attendee, $operator, $reason) {
            $locked = $this->lockPending($attendee);

            $locked->forceFill([
                'status' => EventRegistrationStatus::Rejected,
                'rejection_reason' => trim($reason),
                // Written on rejection too: the same audit trail either way.
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ])->save();

            $this->notify($locked->user_id, 'event.registration_rejected');
        });
    }

    /**
     * Turn down every registration still awaiting a decision on an event.
     *
     * The way out of a queue nobody is going to work through: applications
     * pile up holding seats, and deciding them one at a time is the reason
     * they were abandoned. Nothing was charged, so nothing is refunded.
     *
     * @return int how many were closed out
     */
    public function rejectAllPending(Event $event, User $operator, string $reason): int
    {
        if (trim($reason) === '') {
            throw new EventActionException('api.event.rejection_reason_required');
        }

        return DB::transaction(function () use ($event, $operator, $reason) {
            // Lock the rows being decided, so a concurrent approve either
            // lands first or finds its row no longer pending.
            $ids = EventAttendee::query()
                ->where('event_id', $event->id)
                ->where('status', EventRegistrationStatus::Pending)
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return 0;
            }

            EventAttendee::query()->whereIn('id', $ids)->update([
                'status' => EventRegistrationStatus::Rejected->value,
                'rejection_reason' => trim($reason),
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ]);

            EventAttendee::query()->whereIn('id', $ids)
                ->whereNotNull('user_id')
                ->pluck('user_id')
                ->each(fn ($userId) => $this->notify($userId, 'event.registration_rejected'));

            return $ids->count();
        });
    }

    /**
     * Re-read the row under a lock. The instance the caller holds may have
     * been decided by someone else since it was fetched.
     */
    private function lockPending(EventAttendee $attendee): EventAttendee
    {
        $locked = EventAttendee::query()
            ->whereKey($attendee->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== EventRegistrationStatus::Pending) {
            throw new EventActionException('api.event.not_pending', 409);
        }

        return $locked;
    }

    /**
     * Other pending registrations may have been approved since this one was
     * submitted, so capacity is re-checked here rather than trusted from
     * submission time.
     *
     * Counts approved rows only: this registration is itself seat-holding
     * while pending, so counting seat-holders would always be one over.
     */
    private function assertSeatAvailable(Event $event, EventAttendee $attendee): void
    {
        if ($event->capacity === null) {
            return;
        }

        $taken = EventAttendee::query()
            ->where('event_id', $event->id)
            ->where('status', EventRegistrationStatus::Approved)
            ->whereKeyNot($attendee->getKey())
            ->count();

        if ($taken >= (int) $event->capacity) {
            throw new EventActionException('api.event.approval_would_exceed_capacity', 409);
        }
    }

    /**
     * Charges the approved registration the way the member chose when they
     * applied: from the wallet they named (their own when they named none,
     * as before), or not at all for cash or a card — the seat is confirmed
     * unpaid, and they pay at the desk or from the app.
     */
    private function chargeIfPaid(Event $event, EventAttendee $attendee): void
    {
        $pricePoints = (int) ($event->price_points ?? 0);

        if ((bool) ($event->is_free ?? true) || $pricePoints <= 0) {
            return;
        }

        // A walk-in RSVP has no account to charge.
        if ($attendee->user_id === null) {
            throw new EventActionException('api.event.approve_requires_member');
        }

        // Applied before registrations recorded their price: the event's.
        if ($attendee->payment_state === null) {
            $attendee->forceFill([
                'price_points' => $pricePoints,
                'payment_state' => EventPaymentState::Unpaid,
                'payment_method' => WalletMethod::KEY,
            ])->save();
        }

        if (($attendee->payment_method ?? WalletMethod::KEY) !== WalletMethod::KEY) {
            return;
        }

        try {
            $this->payments->start(PaymentPurpose::EventRegistration, $attendee->user, WalletMethod::KEY, [
                'payable' => $attendee,
                'wallet_owner_type' => $attendee->payment_wallet_owner_type ?? OwnerType::User->value,
                'wallet_owner_id' => $attendee->payment_wallet_owner_id ?? $attendee->user_id,
            ]);
        } catch (InsufficientPointsException $e) {
            throw new EventActionException('api.event.approve_insufficient_points', 422, [
                'current' => $e->current,
                'required' => $e->required,
            ]);
        } catch (ReceptionActionException $e) {
            throw new EventActionException($e->messageKey === 'api.payment.no_wallet' ? 'api.event.wallet_missing' : $e->messageKey, $e->status, $e->params);
        }

        $attendee->refresh();
    }

    private function notify(?int $userId, string $templateKey): void
    {
        if ($userId === null) {
            return;
        }

        NotificationLog::create([
            'user_id' => $userId,
            'channel' => 'push',
            'template_key' => $templateKey,
            'status' => 'sent',
        ]);
    }
}
