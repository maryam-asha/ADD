<?php

namespace App\Services\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventLocationService;
use App\Domain\Forms\Services\FormSubmissionService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Exceptions\InsufficientBalanceException;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use App\Support\Enums\EventRegistrationStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventRegistrationService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly FormSubmissionService $forms,
    ) {}

    /**
     * Register an authenticated member for an event.
     * Takes user profile information (name, email, phone) automatically.
     * Deducts points from member's wallet if paid; free events register immediately.
     *
     * @param  array<string, mixed>  $fieldValues  answers to the event form, if any
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function register(Event $event, User $user, array $fieldValues = []): array
    {
        return DB::transaction(function () use ($event, $user, $fieldValues) {
            // 1. Lock event for update to prevent race conditions on capacity
            $lockedEvent = Event::query()
                ->where('id', $event->id)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Validate event status
            if ($lockedEvent->status === 'cancelled') {
                throw ValidationException::withMessages([
                    'event' => [__('api.event.cancelled')],
                ]);
            }

            // Registration lives elsewhere for this event, so there is nothing
            // to record here — and recording it would invent a seat and an
            // approval the external form knows nothing about.
            if ($lockedEvent->registersExternally()) {
                throw ValidationException::withMessages([
                    'event' => [__('api.event.registers_externally')],
                ]);
            }

            if ($lockedEvent->status === 'draft') {
                throw ValidationException::withMessages([
                    'event' => [__('api.event.not_available')],
                ]);
            }

            // 3. Validate event has not past
            $now = Carbon::now();
            $endAt = $lockedEvent->end_at ? Carbon::parse($lockedEvent->end_at) : null;
            $startAt = $lockedEvent->start_at ? Carbon::parse($lockedEvent->start_at) : null;
            $isPast = $endAt ? $endAt->isBefore($now) : ($startAt ? $startAt->isBefore($now) : false);

            if ($isPast) {
                throw ValidationException::withMessages([
                    'event' => [__('api.event.closed')],
                ]);
            }

            // 4. Validate user is not already registered
            $alreadyRegistered = EventAttendee::query()
                ->where('event_id', $lockedEvent->id)
                ->seatHolding()
                ->where(function ($query) use ($user) {
                    $query->where('user_id', $user->id)
                        ->orWhere('email', $user->email);
                    if ($user->phone) {
                        $query->orWhere('phone', $user->phone);
                    }
                })
                ->exists();

            if ($alreadyRegistered) {
                throw ValidationException::withMessages([
                    'registration' => [__('api.event.already_registered')],
                ]);
            }

            // 5. Validate capacity
            if ($lockedEvent->capacity !== null) {
                $currentAttendeeCount = EventAttendee::query()
                    ->where('event_id', $lockedEvent->id)
                    ->seatHolding()
                    ->lockForUpdate()
                    ->count();

                if ($currentAttendeeCount >= $lockedEvent->capacity) {
                    throw ValidationException::withMessages([
                        'capacity' => [__('api.event.full')],
                    ]);
                }
            }

            // 5b. An event that needs review parks the registration; one
            // that does not admits it here and now.
            $status = $lockedEvent->requires_approval
                ? EventRegistrationStatus::Pending
                : EventRegistrationStatus::Approved;

            // 6. Payment. Points are only ever taken from an approved
            // registration, so a pending one costs nothing and a rejection
            // needs no refund. A member short of points may still apply and
            // top up before the decision.
            $isFree = (bool) ($lockedEvent->is_free ?? true);
            $pricePoints = (int) ($lockedEvent->price_points ?? 0);
            $pointsDeducted = 0;

            // TEMPORARY (config/events.php → EVENT_SKIP_BALANCE_CHECK): skips
            // the debit outright for testing, so a member registers for a paid
            // event without being charged and without a balance check. The
            // debit and the check are one operation, so neither survives alone.
            // Must be false in production — see the config note.
            $skipDebit = (bool) config('events.skip_balance_check', false);

            if ($status === EventRegistrationStatus::Approved && ! $isFree && $pricePoints > 0 && ! $skipDebit) {
                $wallet = $this->wallets->walletFor(OwnerType::User, $user->id);

                $eventTitle = is_array($lockedEvent->title)
                    ? ($lockedEvent->title['en'] ?? $lockedEvent->title['ar'] ?? "Event #{$lockedEvent->id}")
                    : (string) $lockedEvent->title;

                try {
                    // debitGeneral locks the balance and checks it itself;
                    // reading it first and comparing would race.
                    $this->wallets->debitGeneral(
                        $wallet,
                        $pricePoints,
                        "Event registration: {$eventTitle}"
                    );
                } catch (InsufficientBalanceException) {
                    $currentBalance = $this->wallets->generalBalance($wallet);

                    throw ValidationException::withMessages([
                        'points' => [__('api.event.insufficient_points', [
                            'current' => $currentBalance,
                            'required' => $pricePoints,
                            'shortage' => max(0, $pricePoints - $currentBalance),
                        ])],
                    ]);
                }

                $pointsDeducted = $pricePoints;
            }

            // 7. Create the attendee from the member's own profile. Name,
            // phone and email are never asked for on the form — they are
            // already known, and a form field could contradict them.
            $attendee = EventAttendee::create([
                'event_id' => $lockedEvent->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'status' => $status,
                'pending_expires_at' => $status === EventRegistrationStatus::Pending
                    ? $this->pendingDeadlineFor($lockedEvent)
                    : null,
                'attended' => null,
            ]);

            // 8. Answers to the event's own questions, if it asks any —
            // decided by the form itself, not by the listing flag.
            if ($form = $lockedEvent->registrationForm) {
                $this->forms->record($form, $attendee, $fieldValues, $user);
            }

            $locale = app()->getLocale() ?: 'ar';

            if ($status === EventRegistrationStatus::Pending) {
                $successMessage = __('api.event.registration_pending');
            } else {
                $successMessage = $pointsDeducted > 0
                    ? __('api.event.registration_success_points', ['points' => $pointsDeducted])
                    : __('api.event.registration_success');
            }

            return [
                'message' => $successMessage,
                'ticket_id' => $attendee->id,
                'status' => $status->value,
                'requires_approval' => (bool) $lockedEvent->requires_approval,
                'event' => [
                    'id' => $lockedEvent->id,
                    'title' => $lockedEvent->translate('title', $locale),
                    'start_at' => $lockedEvent->start_at?->format('Y-m-d'),
                    'location' => app(EventLocationService::class)->displayLocation($lockedEvent, $locale),
                    'price_points' => $pricePoints,
                ],
            ];
        });
    }

    /**
     * When an undecided registration for this event stops holding its seat.
     *
     * Derived from the event's start so a distant event keeps its
     * applications alive; null when the event has no start to count back
     * from, in which case only an admin closes it out.
     */
    private function pendingDeadlineFor(Event $event): ?Carbon
    {
        if ($event->start_at === null) {
            return null;
        }

        $deadline = Carbon::parse($event->start_at)
            ->subHours((int) config('events.pending_expiry_hours_before_start', 24));

        // An event starting within the window would otherwise mint a
        // deadline already in the past.
        return $deadline->isFuture() ? $deadline : null;
    }
}
