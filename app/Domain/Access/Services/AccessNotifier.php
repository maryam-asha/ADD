<?php

namespace App\Domain\Access\Services;

use App\Domain\Access\Enums\AccessSourceType;
use App\Domain\Access\Models\AccessGrant;
use App\Domain\Booking\Models\Booking;
use App\Domain\Leasing\Models\OfficeLease;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;

/**
 * What a member is told about a door: a code ready, a code stopped, an
 * office door open to them. A code itself never travels in a notification —
 * its text shows on a locked phone's screen; the app shows the code.
 */
class AccessNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function passcodeReady(Booking $booking): void
    {
        $member = $booking->user;

        if ($member === null) {
            return;
        }

        $this->notifications->sendToUser($member, 'access.passcode_ready', [
            'space' => $booking->space?->name ?? '',
            'from' => NotificationText::localTime($booking->start_at),
            'to' => NotificationText::localTime($booking->end_at),
        ], ['target_type' => 'booking', 'target_id' => (string) $booking->id]);
    }

    /** A booking's code stopped by maintenance; an office's goes with its company. */
    public function revoked(AccessGrant $grant): void
    {
        $booking = $grant->source_type === AccessSourceType::Booking ? $grant->booking : null;

        if ($booking?->user === null) {
            return;
        }

        $this->notifications->sendToUser($booking->user, 'access.revoked', [
            'space' => $booking->space?->name ?? '',
        ], ['target_type' => 'booking', 'target_id' => (string) $booking->id]);
    }

    /** Every member of the company who may open its doors. */
    public function officeOpened(OfficeLease $lease): void
    {
        $members = $lease->company?->members()
            ->wherePivot('door_access_enabled', true)
            ->where('users.status', 'active')
            ->get();

        if ($members === null || $members->isEmpty()) {
            return;
        }

        $this->notifications->sendToUsers($members, 'access.office_opened', [
            'space' => $lease->space?->name ?? '',
        ], ['target_type' => 'office_lease', 'target_id' => (string) $lease->id]);
    }
}
