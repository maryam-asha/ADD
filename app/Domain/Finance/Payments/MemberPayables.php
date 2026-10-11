<?php

namespace App\Domain\Finance\Payments;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The record a member is paying for, found only among their own: anyone
 * else's is "not found" (404), so ids cannot be probed. Null for a purpose
 * that pays for no record (a top-up, a plan).
 */
class MemberPayables
{
    /**
     * @throws ModelNotFoundException
     */
    public function find(PaymentPurpose $purpose, ?int $id, User $member): ?Model
    {
        return match ($purpose) {
            PaymentPurpose::Booking => Booking::query()->where('user_id', $member->id)->findOrFail($id),
            PaymentPurpose::CoworkingSession => WalkinSession::query()->where('user_id', $member->id)->findOrFail($id),
            PaymentPurpose::EventRegistration => EventAttendee::query()->where('user_id', $member->id)->findOrFail($id),
            default => null,
        };
    }
}
