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

            $session = $this->sessions->openSessionFor($member)
                ?? throw new ReceptionActionException('api.coworking.no_open_session');

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

        $pending->forceFill([
            'status' => SessionEndRequestStatus::Cancelled,
            'resolved_at' => now(),
        ])->save();
    }

    /**
     * $checkedOutAt defaults to the moment the member pressed. Marked
     * confirmed before closing, so the closure's "supersede whatever is still
     * pending" leaves this row alone; both happen in one transaction, so a
     * closure that refuses (past closing, before check-in) undoes the mark too.
     */
    public function confirm(SessionEndRequest $endRequest, User $operator, ?CarbonInterface $checkedOutAt = null): SessionEndRequest
    {
        return DB::transaction(function () use ($endRequest, $operator, $checkedOutAt) {
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

            $this->closures->closeOut($locked->sessionable, $time);

            return $locked;
        });
    }
}
