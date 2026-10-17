<?php

namespace App\Services\Home;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Ecosystem\Enums\AnnouncementPlacement;
use App\Domain\Ecosystem\Models\Announcement;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventSession;
use App\Domain\Experience\Services\EventBrowseService;
use App\Domain\Foundation\Models\Branch;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Services\Models\ServiceType;
use App\Domain\Services\Services\ServiceCatalogService;
use App\Support\Enums\EventRegistrationStatus;
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
        private readonly ServiceCatalogService $catalog,
        private readonly BookingPresentationService $presentation,
        private readonly EventBrowseService $events,
        private readonly LocalTime $time,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $member): array
    {
        $locale = app()->getLocale();
        $now = now();
        $localNow = $this->time->toLocal($now);
        // "Today" is the district's day, not the server's.
        $dayStart = $localNow->copy()->startOfDay()->utc();
        $dayEnd = $localNow->copy()->addDay()->startOfDay()->utc();

        $session = $this->sessions->openSessionFor($member);

        return [
            'greeting' => $this->greeting($member, $localNow, $locale),
            'unread_notifications' => $member->unreadNotifications()->count(),
            'active_session' => $session === null ? null : $this->session($session, $locale),
            'banners' => $this->banners($locale),
            'today' => $this->today($member, $session, $now, $dayStart, $dayEnd, $locale),
            'explore' => $this->explore($member, $locale),
        ];
    }

    private function greeting(User $member, CarbonInterface $localNow, string $locale): string
    {
        $period = match (true) {
            $localNow->hour >= 5 && $localNow->hour < 12 => 'morning',
            $localNow->hour >= 12 && $localNow->hour < 17 => 'afternoon',
            default => 'evening',
        };

        $greeting = __("api.home.greeting.{$period}", [], $locale);
        $firstName = Str::before(trim((string) $member->name), ' ');

        return $firstName === ''
            ? $greeting
            : __('api.home.greeting.with_name', ['greeting' => $greeting, 'name' => $firstName], $locale);
    }

    /**
     * The card above everything else while a session runs. Its detail —
     * the price, the coverage, ending it — is coworking-sessions/current.
     *
     * @return array<string, mixed>
     */
    private function session(Booking|WalkinSession $session, string $locale): array
    {
        $session->loadMissing(['space.building.branch', 'pendingEndRequest']);

        return [
            'type' => $session->coworkingType(),
            'id' => $session->id,
            'space' => $this->space($session->space, $locale),
            'branch' => $this->branch($session->space->building?->branch, $locale),
            'checked_in_at' => $this->time->toLocal($session->checked_in_at)?->toIso8601String(),
            'end_requested' => $session->pendingEndRequest !== null,
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
     * first. A booking that is the running session is the card above, so
     * it is not listed twice.
     *
     * @return list<array<string, mixed>>
     */
    private function today(User $member, Booking|WalkinSession|null $session, CarbonInterface $now, CarbonInterface $dayStart, CarbonInterface $dayEnd, string $locale): array
    {
        $bookings = Booking::query()
            ->where('user_id', $member->id)
            ->whereIn('status', BookingStatus::open())
            ->where('start_at', '<', $dayEnd)
            ->where('end_at', '>', $now)
            ->when($session instanceof Booking, fn ($query) => $query->whereKeyNot($session->id))
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
    private function explore(User $member, string $locale): array
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
