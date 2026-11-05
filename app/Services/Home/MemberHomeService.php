<?php

namespace App\Services\Home;

use App\Domain\Access\Services\BookingDoorService;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\ArrivalRequestService;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Booking\Services\CurrentBookingLocator;
use App\Domain\Ecosystem\Enums\AnnouncementPlacement;
use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventSession;
use App\Domain\Experience\Services\EventBrowseService;
use App\Domain\Finance\Payments\OutstandingItems;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Services\ServiceCatalogService;
use App\Support\Enums\EventRegistrationStatus;
use App\Support\Presenters\PaymentStatePresenter;
use App\Support\Time\LocalTime;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Everything the member app's home screen draws, in one read: the greeting,
 * the bell's count, the running co-working session, the banners, the day's
 * bookings and events, and "Explore ADD". Each part is read from where it
 * already lives — nothing here decides anything those places do not.
 *
 * Lives outside app/Domain because it reads across Core, Experience and
 * Ecosystem, which the layer guard keeps from importing one another.
 */
class MemberHomeService
{
    public function __construct(
        private readonly CoworkingSessionService $sessions,
        private readonly CurrentBookingLocator $bookings,
        private readonly ArrivalRequestService $arrivals,
        private readonly ServiceCatalogService $catalog,
        private readonly BookingPresentationService $presentation,
        private readonly EventBrowseService $events,
        private readonly OutstandingItems $outstanding,
        private readonly PaymentStatePresenter $payments,
        private readonly LocalTime $time,
        private readonly BookingDoorService $doors,
    ) {}

    /**
     * A visitor (no account, the app's visitor mode) gets the greeting
     * without a name, the banners and "Explore ADD"; the parts about the
     * member's own account are empty.
     *
     * @return array<string, mixed>
     */
    public function for(?User $member): array
    {
        $locale = app()->getLocale();
        $now = now();
        $localNow = $this->time->toLocal($now);
        // "Today" is the district's day, not the server's.
        $dayStart = $localNow->copy()->startOfDay()->utc();
        $dayEnd = $localNow->copy()->addDay()->startOfDay()->utc();

        if ($member === null) {
            return [
                'greeting' => $this->greeting(null, $localNow, $locale),
                'unread_notifications' => 0,
                'active_session' => null,
                'active_booking' => null,
                'banners' => $this->banners($locale),
                'today' => [],
                'explore' => $this->explore(null, $locale),
            ];
        }

        $session = $this->sessions->openSessionFor($member);
        $current = $this->bookings->for($member);

        // The running session's booking and the booking on its own card are
        // the cards above, so `today` does not list them twice.
        $shown = array_values(array_filter([
            $session instanceof Booking ? $session->id : null,
            $current === null ? null : $current[1]->id,
        ]));

        // Closed and still owing, newest close first (OutstandingItems::closedFor,
        // the desk's own rules): the newest of each kind stays its card,
        // awaiting_payment, once nothing live takes the card's place.
        $owed = $this->outstanding->closedFor($member->id);
        $owedSession = $owed->first(fn (Booking|WalkinSession $item) => ! $item instanceof Booking || $item->isCoworking());
        $owedBooking = $owed->first(fn (Booking|WalkinSession $item) => $item instanceof Booking && ! $item->isCoworking());

        return [
            'greeting' => $this->greeting($member, $localNow, $locale),
            'unread_notifications' => $member->unreadNotifications()->count(),
            'active_session' => $session === null
                ? $this->awaitingSession($member, $locale) ?? $this->owingSession($owedSession, $member, $locale)
                : $this->session($session, $locale),
            'active_booking' => match (true) {
                $current !== null => $this->booking($current[0], $current[1], $member, $locale),
                $owedBooking !== null => $this->booking('awaiting_payment', $owedBooking, $member, $locale),
                default => null,
            },
            'banners' => $this->banners($locale),
            'today' => $this->today($member, $shown, $now, $dayStart, $dayEnd, $locale),
            'explore' => $this->explore($member, $locale),
        ];
    }

    private function greeting(?User $member, CarbonInterface $localNow, string $locale): string
    {
        $period = match (true) {
            $localNow->hour >= 5 && $localNow->hour < 12 => 'morning',
            $localNow->hour >= 12 && $localNow->hour < 17 => 'afternoon',
            default => 'evening',
        };

        $greeting = __("api.home.greeting.{$period}", [], $locale);
        $firstName = Str::before(trim((string) $member?->name), ' ');

        return $firstName === ''
            ? $greeting
            : __('api.home.greeting.with_name', ['greeting' => $greeting, 'name' => $firstName], $locale);
    }

    /**
     * The card above everything else while a session runs (state active).
     * Its detail — the price, the coverage, ending it — is
     * coworking-sessions/current.
     *
     * @return array<string, mixed>
     */
    private function session(Booking|WalkinSession $session, string $locale): array
    {
        $session->loadMissing(['space.building.branch', 'pendingEndRequest']);

        return [
            'state' => 'active',
            'type' => $session->coworkingType(),
            'id' => $session->id,
            'arrival_request_id' => null,
            'space' => $this->space($session->space, $locale),
            'branch' => $this->branch($session->space->building?->branch, $locale),
            'requested_at' => null,
            'checked_in_at' => $this->time->toLocal($session->checked_in_at)?->toIso8601String(),
            'end_requested' => $session->pendingEndRequest !== null,
            'checked_out_at' => null,
            'payment' => null,
        ];
    }

    /**
     * The same card while a kiosk scan waits for reception to start the
     * session (state awaiting_confirmation): no session yet, so no id or
     * check-in time. Null when nothing waits.
     *
     * @return array<string, mixed>|null
     */
    private function awaitingSession(User $member, string $locale): ?array
    {
        $request = $this->arrivals->pendingFor($member);

        if ($request === null || ! $request->isCoworkingStart() || $request->space === null) {
            return null;
        }

        $request->loadMissing('space.building.branch');

        return [
            'state' => 'awaiting_confirmation',
            'type' => $request->matched_booking_id !== null ? 'booking' : 'walkin',
            'id' => null,
            'arrival_request_id' => $request->id,
            'space' => $this->space($request->space, $locale),
            'branch' => $this->branch($request->space->building?->branch, $locale),
            'requested_at' => $this->time->toLocal($request->requested_at)?->toIso8601String(),
            'checked_in_at' => null,
            'end_requested' => false,
            'checked_out_at' => null,
            'payment' => null,
        ];
    }

    /**
     * The same card once reception has closed the session and money is
     * still owed (state awaiting_payment): the newest closed coworking
     * session that owes (a walk-in, or a booking on a coworking space —
     * room bookings are active_booking), so the member pays from the card
     * they were watching. `payment` is the block every session shows
     * (PaymentStatePresenter — `pending` while a card payment is in flight);
     * paying is POST member/coworking-sessions/{type}/{id}/pay, and
     * CoworkingSessionPaid tells the app to re-fetch. Null when no session
     * owes.
     *
     * @return array<string, mixed>|null
     */
    private function owingSession(Booking|WalkinSession|null $session, User $member, string $locale): ?array
    {
        if ($session === null) {
            return null;
        }

        return [
            'state' => 'awaiting_payment',
            'type' => $session->coworkingType(),
            'id' => $session->id,
            'arrival_request_id' => null,
            'space' => $this->space($session->space, $locale),
            'branch' => $this->branch($session->space->building?->branch, $locale),
            'requested_at' => null,
            'checked_in_at' => $this->time->toLocal($session->checked_in_at)?->toIso8601String(),
            'end_requested' => false,
            'checked_out_at' => $this->time->toLocal($session->checked_out_at)?->toIso8601String(),
            'payment' => $this->payments->for($session, $member),
        ];
    }

    /**
     * A room, office or hall booking's own card (CurrentBookingLocator), from
     * its check-in window (`ready`) through the booking (`active`, checked
     * in by reception or the door QR) to what it still owes once closed
     * (`awaiting_payment`, the newest room booking that owes, once no live
     * one takes its place). It closes itself at `end_at` or at the desk —
     * the member does not end it — and `payment`, only once it owes, is the
     * block every booking shows, `method` the one it was booked with:
     * paying is POST member/bookings/{booking}/pay, and BookingPaid tells
     * the app to re-fetch.
     *
     * @return array<string, mixed>
     */
    private function booking(string $state, Booking $booking, User $member, string $locale): array
    {
        $booking->loadMissing('space.building.branch');

        return [
            'state' => $state,
            'id' => $booking->id,
            'reference' => $booking->reference,
            'space' => $this->space($booking->space, $locale),
            'branch' => $this->branch($booking->space->building?->branch, $locale),
            'start_at' => $this->time->toLocal($booking->start_at)?->toIso8601String(),
            'end_at' => $this->time->toLocal($booking->end_at)?->toIso8601String(),
            'checked_in_at' => $this->time->toLocal($booking->checked_in_at)?->toIso8601String(),
            'checked_in_via' => $booking->checked_in_via?->value,
            'checked_out_at' => $this->time->toLocal($booking->checked_out_at)?->toIso8601String(),
            'payment' => $state === 'awaiting_payment' ? $this->payments->for($booking, $member) : null,
            // Whether to show the door buttons (POST member/bookings/{id}/door/unlock|lock):
            // only once checked in, and only for a room whose door code is live.
            'door' => ['available' => $state === 'active' && $this->doors->available($booking)],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function banners(string $locale): array
    {
        return Announcement::query()
            ->live()
            ->shownOn(AnnouncementPlacement::App)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Announcement $announcement) => [
                'id' => $announcement->id,
                'type' => $announcement->type->value,
                'type_label' => $announcement->type->label($locale),
                'subtitle' => $announcement->translate('subtitle', $locale),
                'title' => $announcement->translate('title', $locale),
                'description' => $announcement->translate('description', $locale),
                'cta_label' => $announcement->translate('cta_label', $locale),
                'image_url' => $announcement->image_url,
                'link_url' => $announcement->link_url,
            ])
            ->all();
    }

    /**
     * The member's bookings and events still ahead of them today, soonest
     * first. The bookings in $shown are the cards above, so they are not
     * listed twice.
     *
     * @param  list<int>  $shown
     * @return list<array<string, mixed>>
     */
    private function today(User $member, array $shown, CarbonInterface $now, CarbonInterface $dayStart, CarbonInterface $dayEnd, string $locale): array
    {
        $bookings = Booking::query()
            ->where('user_id', $member->id)
            ->whereIn('status', BookingStatus::open())
            ->where('start_at', '<', $dayEnd)
            ->where('end_at', '>', $now)
            ->whereKeyNot($shown)
            ->with('space.building.branch')
            ->get()
            ->map(fn (Booking $booking) => [
                'kind' => 'booking',
                'id' => $booking->id,
                'title' => $booking->space->displayTitle($locale),
                'space' => $this->space($booking->space, $locale),
                'branch' => $this->branch($booking->space->building?->branch, $locale),
                'image_url' => $booking->space->main_image,
                ...$this->times($booking->start_at, $booking->end_at, $locale),
                'attendees_count' => null,
                'status' => $booking->status->value,
                'status_label' => $booking->status->label($locale),
            ]);

        $events = Event::query()
            ->where('status', 'published')
            ->whereHas('attendees', fn ($query) => $query
                ->where('user_id', $member->id)
                ->whereIn('status', EventRegistrationStatus::seatHoldingValues()))
            ->where('start_at', '<', $dayEnd)
            // An event with no end is taken to last the day it starts.
            ->where(fn ($query) => $query
                ->where('end_at', '>', $now)
                ->orWhere(fn ($query) => $query->whereNull('end_at')->where('start_at', '>=', $dayStart)))
            ->with([
                'sessions',
                'space.building.branch',
                'branch',
                'attendees' => fn ($query) => $query->where('user_id', $member->id),
            ])
            ->withCount('seatHoldingAttendees as attendees_count')
            ->get()
            ->map(fn (Event $event) => $this->eventItem($event, $now, $dayEnd, $locale))
            ->filter();

        return $bookings->concat($events)
            ->sortBy('start_at')
            ->values()
            ->all();
    }

    /**
     * An event runs today if one of its sittings does; the times shown are
     * that sitting's, not the envelope around every sitting.
     *
     * @return array<string, mixed>|null
     */
    private function eventItem(Event $event, CarbonInterface $now, CarbonInterface $dayEnd, string $locale): ?array
    {
        [$start, $end] = [$event->start_at, $event->end_at];

        if ($event->sessions->isNotEmpty()) {
            $sitting = $event->sessions->first(
                fn (EventSession $sitting) => $sitting->start_at->lt($dayEnd) && $sitting->end_at->gt($now)
            );

            if ($sitting === null) {
                return null;
            }

            [$start, $end] = [$sitting->start_at, $sitting->end_at];
        }

        $status = $event->attendees->first()?->status;
        $status = $status instanceof BackedEnum ? $status->value : $status;

        return [
            'kind' => 'event',
            'id' => $event->id,
            'title' => $event->translate('title', $locale),
            'space' => $event->space === null ? null : $this->space($event->space, $locale),
            'branch' => $this->branch($event->space?->building?->branch ?? $event->branch, $locale),
            'image_url' => $event->image_url,
            ...$this->times($start, $end, $locale),
            'attendees_count' => (int) $event->attendees_count,
            'status' => $status,
            'status_label' => $this->events->translateRegistrationStatus($status, $locale),
        ];
    }

    /**
     * Start and end in the district's offset, and the lines a card prints.
     *
     * @return array{start_at: string, end_at: ?string, date_label: string, time_label: string}
     */
    private function times(CarbonInterface $start, ?CarbonInterface $end, string $locale): array
    {
        return [
            'start_at' => $this->time->toLocal($start)->toIso8601String(),
            'end_at' => $this->time->toLocal($end)?->toIso8601String(),
            'date_label' => $this->presentation->dateLabel($start, $locale),
            'time_label' => $end === null
                ? $this->time->toLocal($start)->format('h:i A')
                : $this->presentation->timeLabel($start, $end, $locale),
        ];
    }

    /**
     * "Explore ADD": the services put on the home screen. Each one's page
     * and button are services/{slug}.
     *
     * @return list<array<string, mixed>>
     */
    private function explore(?User $member, string $locale): array
    {
        return $this->catalog->homeServicesForMember($member)
            ->map(fn (ServiceType $service) => [
                'id' => $service->id,
                'slug' => $service->slug,
                'name' => $service->translate('name', $locale),
                'description' => $service->translate('description', $locale),
                'image_url' => $service->image_url,
                'is_coming_soon' => $service->is_coming_soon,
                'axis' => [
                    'slug' => $service->axis->slug,
                    'name' => $service->axis->translate('name', $locale),
                    'color' => $service->axis->color,
                ],
            ])
            ->all();
    }

    /** @return array{id: int, name: string, type: string} */
    private function space(Space $space, string $locale): array
    {
        return [
            'id' => $space->id,
            'name' => $space->displayTitle($locale),
            'type' => $space->space_type->value,
        ];
    }

    /** @return array{id: int, name: ?string}|null */
    private function branch(?Branch $branch, string $locale): ?array
    {
        return $branch === null ? null : [
            'id' => $branch->id,
            'name' => $branch->translate('name', $locale),
        ];
    }
}
