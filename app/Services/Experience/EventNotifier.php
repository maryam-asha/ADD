<?php

namespace App\Services\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Services\EventLocationService;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;

/**
 * What a member is told about their registration: which event and on which
 * day, in their own language — and, as it comes up, a reminder with its time
 * and place. A registration made without an account has no one to tell.
 */
class EventNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly EventLocationService $locations,
    ) {}

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

    /** Reminder, once per registration and $stage (`1d`, `2h`). */
    public function reminder(EventAttendee $attendee, string $stage): void
    {
        $event = $attendee->event;

        if ($attendee->user === null || $event === null) {
            return;
        }

        // The place in each language: an off-site address as written, an
        // on-site room by its branch and space names.
        $location = [];
        foreach (SetLocaleFromHeader::SUPPORTED_LOCALES as $locale) {
            $location[$locale] = (string) $this->locations->displayLocation($event, $locale);
        }

        $this->notifications->sendToUserOnce(
            $attendee->user,
            "event.reminder:{$attendee->id}:{$stage}",
            array_filter($location) === [] ? 'event.reminder_without_location' : 'event.reminder',
            $this->params($attendee) + [
                'time' => NotificationText::localTime($event->start_at),
                'location' => $location,
            ],
            $this->target($attendee),
        );
    }

    private function tell(EventAttendee $attendee, string $templateKey): void
    {
        if ($attendee->user === null || $attendee->event === null) {
            return;
        }

        $this->notifications->sendToUser($attendee->user, $templateKey, $this->params($attendee), $this->target($attendee));
    }

    /** @return array<string, mixed> */
    private function params(EventAttendee $attendee): array
    {
        $event = $attendee->event;

        return [
            'event' => $event->title,
            'date' => $event->start_at === null ? '' : NotificationText::localDate($event->start_at),
        ];
    }

    /** @return array<string, string> */
    private function target(EventAttendee $attendee): array
    {
        return ['target_type' => 'event', 'target_id' => (string) $attendee->event_id];
    }
}
