<?php

namespace App\Services\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;

/**
 * What a member is told about their registration: which event and on which
 * day, in their own language. A registration made without an account has no
 * one to tell.
 */
class EventNotifier
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function approved(EventAttendee $attendee): void
    {
        $this->tell($attendee, 'event.registration_approved');
    }

    public function rejected(EventAttendee $attendee): void
    {
        $this->tell($attendee, 'event.registration_rejected');
    }

    /** Its payment window ran out, so the seat was released. */
    public function expired(EventAttendee $attendee): void
    {
        $this->tell($attendee, 'event.registration_expired');
    }

    private function tell(EventAttendee $attendee, string $templateKey): void
    {
        $member = $attendee->user;
        $event = $attendee->event;

        if ($member === null || $event === null) {
            return;
        }

        $this->notifications->sendToUser($member, $templateKey, [
            'event' => $event->title,
            'date' => $event->start_at === null ? '' : NotificationText::localDate($event->start_at),
        ], [
            'target_type' => 'event',
            'target_id' => (string) $event->id,
        ]);
    }
}
