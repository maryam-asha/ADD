<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Models\Booking;
use App\Http\Middleware\SetLocaleFromHeader;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;
use Carbon\CarbonInterface;

/**
 * What a member — or the staff who decide it — is told about a booking:
 * which space, on which day and at what time, in their own language, and
 * how many points came back when any did. A notice whose money part would
 * read "0 points" uses its `_no_refund` wording instead.
 *
 * Never sent for something the member just did themselves (cancelling
 * their own booking): the app already showed them the result.
 */
class BookingNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly BookingPaymentLine $paymentLine,
    ) {}

    /**
     * Approved, with how it was or will be paid. `booking.approved` (no
     * payment line) is kept only for the notices stored before.
     */
    public function approved(Booking $booking): void
    {
        $this->tellMember($booking, 'booking.approved_with_payment', ['payment' => $this->paymentLine->for([$booking])]);
    }

    /**
     * Confirmed the moment it was made (or the moment its card went
     * through), with how it was or will be paid. One the desk made for the
     * member says so. Called after the booking's transaction commits
     * (ConfirmedBookingNotice), so the payment line reads what was saved.
     */
    public function confirmed(Booking $booking, bool $byReception = false): void
    {
        $this->tellMember(
            $booking,
            $byReception ? 'booking.booked_for_you' : 'booking.confirmed',
            ['payment' => $this->paymentLine->for([$booking])],
        );
    }

    /**
     * Several dates booked in one go: one notice for all of them, with what
     * they cost together, rather than one per date.
     *
     * @param  list<Booking>  $bookings  confirmed, all the same member's
     */
    public function confirmedBatch(array $bookings): void
    {
        if (count($bookings) === 1) {
            $this->confirmed($bookings[0]);

            return;
        }

        $first = $bookings[0];

        if ($first->user === null) {
            return;
        }

        $this->notifications->sendToUser($first->user, 'booking.confirmed_batch', [
            'count' => count($bookings),
            'space' => $this->joined(collect($bookings)->map(fn (Booking $booking) => $booking->space?->name ?? '')->unique(fn ($name) => json_encode($name))->values()->all()),
            'dates' => $this->joined(collect($bookings)->map(fn (Booking $booking) => NotificationText::localDate($booking->start_at))->unique()->values()->all()),
            'payment' => $this->paymentLine->for($bookings),
        ], $this->target($first));
    }

    public function rejected(Booking $booking, int $refundedPoints): void
    {
        $this->tellMember($booking, $refundedPoints > 0 ? 'booking.rejected' : 'booking.rejected_no_refund', ['points' => $refundedPoints]);
    }

    public function lapsed(Booking $booking, int $refundedPoints): void
    {
        $this->tellMember($booking, $refundedPoints > 0 ? 'booking.lapsed' : 'booking.lapsed_no_refund');
    }

    /** Cancelled by the desk, not by the member. */
    public function cancelled(Booking $booking, int $refundedPoints): void
    {
        $this->tellMember($booking, $refundedPoints > 0 ? 'booking.cancelled' : 'booking.cancelled_no_refund', ['points' => $refundedPoints]);
    }

    public function paymentExpired(Booking $booking): void
    {
        $this->tellMember($booking, 'booking.payment_expired');
    }

    public function noShow(Booking $booking): void
    {
        $this->tellMember($booking, 'booking.no_show');
    }

    /** Unpaid, and its member had not come by $releasedAt: the seat was let go. */
    public function releasedNoShow(Booking $booking, CarbonInterface $releasedAt): void
    {
        $this->tellMember($booking, 'booking.released_no_show', ['deadline' => NotificationText::localTime($releasedAt)]);
    }

    public function affectedByMaintenance(Booking $booking): void
    {
        $this->tellMember($booking, 'booking.affected_by_maintenance');
    }

    /** Reminder: it starts within the half hour. Sent once per booking. */
    public function startingSoon(Booking $booking): void
    {
        $this->remindMember($booking, 'booking.reminder', "booking.reminder:{$booking->id}");
    }

    /** Reminder: the member is in it, and it ends within minutes. Sent once per booking. */
    public function endingSoon(Booking $booking): void
    {
        $this->remindMember($booking, 'booking.ending_soon', "booking.ending_soon:{$booking->id}", [
            'minutes' => max(1, (int) ceil(now()->diffInSeconds($booking->end_at, false) / 60)),
            'time' => NotificationText::localTime($booking->end_at),
        ]);
    }

    /** Whoever may approve bookings (`reception.approve`), in the dashboard. */
    public function pendingApproval(Booking $booking): void
    {
        $this->notifications->sendToHolders(
            'reception.approve',
            'booking.pending_approval',
            $this->params($booking) + ['member' => (string) $booking->user?->name],
            $this->target($booking),
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function tellMember(Booking $booking, string $templateKey, array $extra = []): void
    {
        $member = $booking->user;

        if ($member === null) {
            return;
        }

        $this->notifications->sendToUser($member, $templateKey, $this->params($booking) + $extra, $this->target($booking));
    }

    /**
     * @param  array<string, mixed>  $override  wins over the booking's own params
     */
    private function remindMember(Booking $booking, string $templateKey, string $dedupeKey, array $override = []): void
    {
        if ($booking->user === null) {
            return;
        }

        $this->notifications->sendToUserOnce($booking->user, $dedupeKey, $templateKey, $override + $this->params($booking), $this->target($booking));
    }

    /** @return array<string, mixed> */
    private function params(Booking $booking): array
    {
        return [
            'space' => $booking->space?->name ?? '',
            'date' => NotificationText::localDate($booking->start_at),
            'time' => NotificationText::localTime($booking->start_at),
        ];
    }

    /**
     * A list in each language, from values that may themselves be
     * translatable (a space's name).
     *
     * @param  list<string|array<string, mixed>>  $values
     * @return array<string, string>
     */
    private function joined(array $values): array
    {
        $lists = [];

        foreach (SetLocaleFromHeader::SUPPORTED_LOCALES as $locale) {
            $lists[$locale] = implode(
                __('notifications.booking.list_separator', [], $locale),
                array_map(fn ($value) => is_array($value) ? NotificationText::pick($value, $locale) : (string) $value, $values),
            );
        }

        return $lists;
    }

    /** @return array<string, string> what the app opens */
    private function target(Booking $booking): array
    {
        return ['target_type' => 'booking', 'target_id' => (string) $booking->id];
    }
}
