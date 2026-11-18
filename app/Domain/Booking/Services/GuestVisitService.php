<?php

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Enums\GuestVisitStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\GuestVisit;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Identity\Models\User;
use App\Domain\Settings\Services\SettingService;
use App\Services\Notification\NotificationService;
use App\Services\Notification\NotificationText;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Guests of a member in a coworking session. Only coworking: a room or
 * office booking's guests are the booking's, never charged.
 *
 * Reception records the guest on arrival; they are free for
 * `guest.grace_minutes`. Checked out within it, nothing is billed. Still
 * inside at its end, the visit becomes a walk-in billed from the arrival —
 * on the guest's own account when their phone has one, otherwise on the
 * host's, who may decline that while the grace runs. A phone gets
 * `guest.free_visits_per_day` graces a day; after that the visit bills from
 * the arrival at once. The host's own session does not close at the desk
 * while their guest is inside (SessionClosureService).
 */
class GuestVisitService
{
    /** How long before the grace ends the host is reminded. */
    public const REMINDER_MINUTES = 5;

    public function __construct(
        private readonly OpenCoworkingSessionLocator $openSessions,
        private readonly WalkInCapacityService $walkIns,
        private readonly SessionClosureService $closures,
        private readonly SettingService $settings,
        private readonly NotificationService $notifications,
    ) {}

    public function register(User $host, string $phone, ?string $name, User $operator): GuestVisit
    {
        $visit = DB::transaction(function () use ($host, $phone, $name, $operator) {
            User::query()->whereKey($host->id)->lockForUpdate()->first();

            $hostSession = $this->openSessions->for($host);

            if ($hostSession === null) {
                throw new ReceptionActionException('api.guest.host_not_in_session');
            }

            $guest = User::query()->where('phone', $phone)->first();

            if ($guest?->is($host)) {
                throw new ReceptionActionException('api.guest.host_is_guest');
            }

            if ($guest !== null && $this->openSessions->for($guest) !== null) {
                throw new ReceptionActionException('api.guest.already_in_session', 409);
            }

            if ($guest === null && blank($name)) {
                throw new ReceptionActionException('api.guest.name_required');
            }

            if (GuestVisit::query()->active()->where('guest_phone', $phone)->exists()) {
                throw new ReceptionActionException('api.guest.already_visiting', 409);
            }

            $max = (int) $this->settings->get('guest.max_active_per_host', 3);
            $inside = GuestVisit::query()->active()->where('host_user_id', $host->id)->count()
                + WalkinSession::query()->open()->where('user_id', $host->id)->whereNotNull('guest_visit_id')->count();

            if ($inside >= $max) {
                throw new ReceptionActionException('api.guest.host_limit', 422, ['max' => $max]);
            }

            $space = $hostSession->space;
            $this->walkIns->assertGuestSeat($space, $guest);

            $now = now();

            return GuestVisit::create([
                'host_user_id' => $host->id,
                'guest_user_id' => $guest?->id,
                'guest_name' => $guest?->name ?? trim((string) $name),
                'guest_phone' => $phone,
                'space_id' => $space->id,
                'arrived_at' => $now,
                'grace_ends_at' => $now->copy()->addMinutes($this->graceMinutesFor($phone)),
                'status' => GuestVisitStatus::InGrace,
                'recorded_by' => $operator->id,
            ]);
        });

        $this->notifications->sendToUser(
            $host,
            $visit->billsHost() ? 'guest.registered_billable' : 'guest.registered',
            ['guest' => $visit->guest_name, 'time' => NotificationText::localTime($visit->grace_ends_at)],
            $visit->notificationTarget(),
        );

        return $visit;
    }

    /**
     * Reception checks the guest out. Within the grace, or declined by the
     * host, nothing is billed. Past the grace, the visit is converted and its
     * session closed now — the same bill the sweep would have made.
     *
     * @return WalkinSession|null the session billed, if any
     */
    public function checkOut(GuestVisit $visit): ?WalkinSession
    {
        return DB::transaction(function () use ($visit) {
            $locked = GuestVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            match ($locked->status) {
                GuestVisitStatus::Converted => throw new ReceptionActionException('api.guest.already_converted', 409),
                GuestVisitStatus::Left => throw new ReceptionActionException('api.guest.already_left', 409),
                default => null,
            };

            if ($locked->status === GuestVisitStatus::InGrace && now()->greaterThanOrEqualTo($locked->grace_ends_at)) {
                $session = $this->startSession($locked);

                // A registered guest already in a session of their own: the
                // visit ended on it, and that session is checked out by itself.
                if ($session === null) {
                    return null;
                }

                $this->closures->closeOut($session, now());

                return $session->fresh();
            }

            $locked->forceFill(['status' => GuestVisitStatus::Left, 'left_at' => now()])->save();

            return null;
        });
    }

    /** The host refuses to pay for a guest with no account of their own, while the grace runs. */
    public function decline(GuestVisit $visit, User $host): void
    {
        $declined = DB::transaction(function () use ($visit, $host) {
            $locked = GuestVisit::query()->whereKey($visit->id)->lockForUpdate()->firstOrFail();

            if ($locked->host_user_id !== $host->id || ! $locked->billsHost() || $locked->status !== GuestVisitStatus::InGrace) {
                throw new ReceptionActionException('api.guest.not_declinable', 409);
            }

            $locked->forceFill(['status' => GuestVisitStatus::Declined, 'host_declined_at' => now()])->save();

            return $locked;
        });

        $this->notifications->sendToHolders('reception.view', 'guest.host_declined', [
            'host' => (string) $host->name,
            'guest' => $declined->guest_name,
        ], $declined->notificationTarget());
    }

    /**
     * The visit's grace is over and the guest still inside: their walk-in
     * starts, from their arrival. Nothing when the visit has moved on, or
     * its grace has not ended. A registered guest already in a session of
     * their own simply ends the visit on it.
     */
    public function convert(GuestVisit $visit): ?WalkinSession
    {
        $session = DB::transaction(function () use ($visit) {
            $locked = GuestVisit::query()->whereKey($visit->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== GuestVisitStatus::InGrace || now()->lessThan($locked->grace_ends_at)) {
                return null;
            }

            return $this->startSession($locked);
        });

        if ($session !== null) {
            $this->tellOfConversion($visit->fresh(), $session);
        }

        return $session;
    }

    /**
     * The minute job (guests:run): visits still open at their branch's
     * closing time end there; hosts hear their grace is ending; and every
     * visit past its grace is converted.
     */
    public function sweep(): void
    {
        $timezone = $this->settings->get('app.timezone', 'Asia/Damascus');
        $now = Carbon::now($timezone);

        GuestVisit::query()->active()->with('space.building.branch')->chunkById(100, function ($visits) use ($now) {
            foreach ($visits as $visit) {
                $closing = $this->closures->closingTimeFor($visit->space->building->branch, $now);

                if ($closing === null || $now->format('H:i') <= $closing) {
                    continue;
                }

                GuestVisit::query()->whereKey($visit->id)->active()->update([
                    'status' => GuestVisitStatus::Left->value,
                    'left_at' => $now->copy()->setTimeFromTimeString($closing)->max($visit->arrived_at)->utc(),
                    'updated_at' => now(),
                ]);
            }
        });

        GuestVisit::query()
            ->where('status', GuestVisitStatus::InGrace)
            ->whereNull('guest_user_id')
            ->where('grace_ends_at', '>', now())
            ->where('grace_ends_at', '<=', now()->addMinutes(self::REMINDER_MINUTES))
            ->with('host')
            ->each(fn (GuestVisit $visit) => $this->notifications->sendToUserOnce(
                $visit->host,
                "guest_visit:{$visit->id}:grace_ending",
                'guest.grace_ending',
                ['guest' => $visit->guest_name, 'time' => NotificationText::localTime($visit->grace_ends_at)],
                $visit->notificationTarget(),
            ));

        GuestVisit::query()
            ->where('status', GuestVisitStatus::InGrace)
            ->where('grace_ends_at', '<=', now())
            ->get()
            ->each(fn (GuestVisit $visit) => $this->convert($visit));
    }

    /** Called with the visit locked, inside a transaction. */
    private function startSession(GuestVisit $visit): ?WalkinSession
    {
        if ($visit->guest !== null) {
            $own = $this->openSessions->for($visit->guest);

            if ($own !== null) {
                GuestVisit::endOnOwnSession($visit->guest, $own instanceof WalkinSession ? $own : null);

                return null;
            }
        }

        $session = $this->walkIns->startFromGuestVisit($visit);

        $visit->forceFill([
            'status' => GuestVisitStatus::Converted,
            'converted_at' => now(),
            'walkin_session_id' => $session->id,
        ])->save();

        return $session;
    }

    private function tellOfConversion(GuestVisit $visit, WalkinSession $session): void
    {
        $placeholders = [
            'guest' => $visit->guest_name,
            'space' => $visit->space?->name ?? '',
            'time' => NotificationText::localTime($visit->arrived_at),
        ];

        if ($visit->billsHost()) {
            $this->notifications->sendToUser($visit->host, 'guest.converted_to_host', $placeholders, $session->notificationTarget());

            return;
        }

        $this->notifications->sendToUser($visit->guest, 'guest.converted_to_guest', $placeholders, $session->notificationTarget());
        $this->notifications->sendToUser($visit->host, 'guest.converted_registered', $placeholders, $visit->notificationTarget());
    }

    /** The grace this phone gets today: none once its free visits are used. */
    private function graceMinutesFor(string $phone): int
    {
        $timezone = $this->settings->get('app.timezone', 'Asia/Damascus');
        $free = (int) $this->settings->get('guest.free_visits_per_day', 1);

        $used = GuestVisit::query()
            ->where('guest_phone', $phone)
            ->where('arrived_at', '>=', Carbon::now($timezone)->startOfDay()->utc())
            ->whereColumn('grace_ends_at', '>', 'arrived_at')
            ->count();

        return $used >= $free ? 0 : max(0, (int) $this->settings->get('guest.grace_minutes', 15));
    }
}
