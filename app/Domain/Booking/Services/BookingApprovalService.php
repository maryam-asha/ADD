<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Identity\Models\NotificationLog;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Both actions lock a fresh copy of the Booking row before deciding — the
 * same pattern BookingCancellationService::cancel() already uses — and act
 * only on that locked copy, never the caller's in-memory $booking.
 */
class BookingApprovalService
{
    public function approve(Booking $booking, User $operator): void
    {
        DB::transaction(function () use ($booking, $operator) {
            $locked = $this->lockPending($booking);

            $locked->forceFill([
                'status' => BookingStatus::Confirmed,
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ])->save();

            $this->notify($locked->user_id, 'booking.approved');
        });
    }

    public function reject(Booking $booking, User $operator, string $reason): void
    {
        if (trim($reason) === '') {
            throw new ReceptionActionException('api.booking.rejection_reason_required');
        }

        DB::transaction(function () use ($booking, $operator, $reason) {
            $locked = $this->lockPending($booking);

            $locked->forceFill([
                'status' => BookingStatus::Rejected,
                'rejection_reason' => $reason,
                'approved_by' => $operator->id,
                'approved_at' => now(),
            ])->save();

            $this->notify($locked->user_id, 'booking.rejected');
        });
    }

    private function lockPending(Booking $booking): Booking
    {
        $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== BookingStatus::Pending) {
            throw new ReceptionActionException('api.booking.not_pending', 409);
        }

        return $locked;
    }

    private function notify(int $userId, string $templateKey): void
    {
        NotificationLog::create([
            'user_id' => $userId,
            'channel' => 'push',
            'template_key' => $templateKey,
            'status' => 'sent',
        ]);
    }
}
