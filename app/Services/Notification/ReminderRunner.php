<?php

namespace App\Services\Notification;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Enums\PaymentState;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\BookingNotifier;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoiceNotifier;
use App\Domain\Leasing\Enums\OfficeLeaseStatus;
use App\Domain\Leasing\Models\OfficeLease;
use App\Domain\Leasing\Services\LeaseNotifier;
use App\Domain\Membership\Enums\MembershipStatus;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Services\MembershipNotifier;
use App\Domain\Settings\Services\SettingService;
use App\Services\Experience\EventNotifier;
use App\Support\Enums\EventRegistrationStatus;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The notices a time brings rather than an action
 * (docs/superpowers/plans/2026-11-02-notifications-phase-3.md), run every
 * five minutes by `notifications:send-reminders`. Each reminder looks only
 * inside its window and only at subjects still live, and is sent once per
 * person through its dedupe key — so a run finding the same booking again
 * sends nothing, a window the runner missed is skipped rather than sent
 * late, and a booking cancelled before its reminder gets none.
 */
class ReminderRunner
{
    public function __construct(
        private readonly BookingNotifier $bookings,
        private readonly EventNotifier $events,
        private readonly MembershipNotifier $memberships,
        private readonly LeaseNotifier $leases,
        private readonly InvoiceNotifier $invoices,
        private readonly NotificationService $notifications,
        private readonly SettingService $settings,
    ) {}

    /** @return array<string, int> how many subjects each reminder found in its window */
    public function run(): array
    {
        return [
            'bookings_starting' => $this->bookingsStartingSoon(),
            'bookings_ending' => $this->bookingsEndingSoon(),
            'events' => $this->eventsComingUp('1d', now()->addHours(2), now()->addDay())
                + $this->eventsComingUp('2h', now(), now()->addHours(2)),
            'plans_ending' => $this->plansEndingSoon('7d', now()->addDay(), now()->addDays(7))
                + $this->plansEndingSoon('1d', now(), now()->addDay()),
            'plans_ended' => $this->plansEnded(),
            'leases_ending' => $this->leasesEndingSoon(),
            'invoices_overdue' => $this->invoicesOverdue(),
            'overdue_digest' => $this->overdueDigest(),
            'sessions_unpaid' => $this->sessionsUnpaid(),
        ];
    }

    /**
     * Staff: how many invoices are overdue, once a day from 09:00 local —
     * not at midnight — and only when there are any.
     *
     * @return int the overdue invoices counted, or 0 before nine
     */
    private function overdueDigest(): int
    {
        $local = now()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'));

        if ($local->hour < 9) {
            return 0;
        }

        $count = Invoice::query()->overdue()->count();

        if ($count > 0) {
            $this->notifications->sendToHoldersOnce('invoices.view', 'invoice.overdue_digest:'.$local->toDateString(), 'invoice.overdue_digest', [
                'count' => $count,
            ], ['target_type' => 'invoices', 'target_id' => 'overdue']);
        }

        return $count;
    }

    /** Confirmed, starting within the half hour. */
    private function bookingsStartingSoon(): int
    {
        return $this->each(
            Booking::query()
                ->where('status', BookingStatus::Confirmed)
                ->where('start_at', '>', now())
                ->where('start_at', '<=', now()->addMinutes(30))
                ->with(['space', 'user']),
            fn (Booking $booking) => $this->bookings->startingSoon($booking),
        );
    }

    /** In use (checked in, not out), ending within ten minutes. */
    private function bookingsEndingSoon(): int
    {
        return $this->each(
            Booking::query()
                ->where('status', BookingStatus::Confirmed)
                ->whereNotNull('checked_in_at')
                ->whereNull('checked_out_at')
                ->where('end_at', '>', now())
                ->where('end_at', '<=', now()->addMinutes(10))
                ->with(['space', 'user']),
            fn (Booking $booking) => $this->bookings->endingSoon($booking),
        );
    }

    /** Approved registrations of a published event starting in (from, to]. */
    private function eventsComingUp(string $stage, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->each(
            EventAttendee::query()
                ->where('status', EventRegistrationStatus::Approved)
                ->whereNotNull('user_id')
                ->whereHas('event', fn (Builder $event) => $event
                    ->where('status', 'published')
                    ->where('start_at', '>', $from)
                    ->where('start_at', '<=', $to))
                ->with(['event', 'user']),
            fn (EventAttendee $attendee) => $this->events->reminder($attendee, $stage),
        );
    }

    /** Active, not renewing itself, not already renewed, ending in (from, to]. */
    private function plansEndingSoon(string $stage, CarbonInterface $from, CarbonInterface $to): int
    {
        return $this->each(
            $this->unrenewedPlans()
                ->where('auto_renew', false)
                ->where('current_period_end', '>', $from)
                ->where('current_period_end', '<=', $to),
            fn (Membership $membership) => $this->memberships->expiringSoon($membership, $stage),
        );
    }

    /** Ended within the last day, and nothing follows it. */
    private function plansEnded(): int
    {
        return $this->each(
            $this->unrenewedPlans()
                ->where('current_period_end', '>', now()->subDay())
                ->where('current_period_end', '<=', now()),
            fn (Membership $membership) => $this->memberships->expired($membership),
        );
    }

    /** Active, not renewing itself, ending 25–30 days from today: a lease shorter than that is not "ending soon" news. */
    private function leasesEndingSoon(): int
    {
        $today = $this->today();

        return $this->each(
            OfficeLease::query()
                ->where('status', OfficeLeaseStatus::Active)
                ->where('auto_renew', false)
                ->whereDate('ends_on', '>=', $today->copy()->addDays(25)->toDateString())
                ->whereDate('ends_on', '<=', $today->copy()->addDays(30)->toDateString())
                ->with(['company', 'space']),
            fn (OfficeLease $lease) => $this->leases->endingSoon($lease),
        );
    }

    /** Open, and fell due within the last week: one overdue for months is old news. */
    private function invoicesOverdue(): int
    {
        $today = $this->today();

        return $this->each(
            Invoice::query()
                ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid])
                ->whereDate('due_at', '<', $today->toDateString())
                ->whereDate('due_at', '>=', $today->copy()->subDays(7)->toDateString()),
            fn (Invoice $invoice) => $this->invoices->overdue($invoice),
        );
    }

    /** Checked out a day or two ago, still owing points. */
    private function sessionsUnpaid(): int
    {
        $unpaid = fn (Builder $query) => $query
            ->whereNotNull('checked_out_at')
            ->where('checked_out_at', '>', now()->subHours(48))
            ->where('checked_out_at', '<=', now()->subHours(24))
            ->where('payment_state', PaymentState::Unpaid)
            ->where('price_points', '>', 0)
            ->with('user');

        $remind = function (Booking|WalkinSession $session) {
            if ($session->user === null) {
                return;
            }

            $type = $session->coworkingType();

            $this->notifications->sendToUserOnce($session->user, "coworking.unpaid_reminder:{$type}:{$session->id}", 'coworking.unpaid_reminder', [
                'points' => (int) $session->price_points,
                'date' => NotificationText::localDate($session->checked_out_at),
            ], $session->notificationTarget());
        };

        return $this->each($unpaid(WalkinSession::query()), $remind)
            + $this->each($unpaid(Booking::query()->where('status', BookingStatus::Completed)), $remind);
    }

    /** Live plans no renewal follows. */
    private function unrenewedPlans(): Builder
    {
        return Membership::query()
            ->where('status', MembershipStatus::Active)
            ->whereNotExists(fn ($next) => $next->selectRaw('1')
                ->from('memberships as next')
                ->whereColumn('next.renewed_from_id', 'memberships.id'))
            ->with('plan');
    }

    /**
     * Sends $remind for each subject; one that fails is logged and the rest
     * carry on.
     *
     * @return int how many subjects were found
     */
    private function each(Builder $subjects, Closure $remind): int
    {
        $found = 0;

        $subjects->chunkById(100, function ($chunk) use ($remind, &$found) {
            foreach ($chunk as $subject) {
                $found++;

                try {
                    $remind($subject);
                } catch (Throwable $e) {
                    Log::error('[Reminders] could not send a reminder', [
                        'subject' => $subject::class.'#'.$subject->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $found;
    }

    /** Today in the business's own timezone — leases and invoices are dated by days there. */
    private function today(): CarbonInterface
    {
        return now()->setTimezone($this->settings->get('app.timezone', 'Asia/Damascus'))->startOfDay();
    }
}
