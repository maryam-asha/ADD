<?php

namespace App\Services\Experience;

use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Services\WalletService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventRegistrationService
{
    public function __construct(
        private readonly WalletService $wallets,
    ) {}

    /**
     * Register an authenticated member for an event.
     * Takes user profile information (name, email, phone) automatically.
     * Deducts points from member's wallet if paid; free events register immediately.
     *
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function register(Event $event, User $user): array
    {
        return DB::transaction(function () use ($event, $user) {
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
                    ->lockForUpdate()
                    ->count();

                if ($currentAttendeeCount >= $lockedEvent->capacity) {
                    throw ValidationException::withMessages([
                        'capacity' => [__('api.event.full')],
                    ]);
                }
            }

            // 6. Handle Payment (Free vs Points)
            $isFree = (bool) ($lockedEvent->is_free ?? true);
            $pricePoints = (int) ($lockedEvent->price_points ?? 0);
            $pointsDeducted = 0;

            if (!$isFree && $pricePoints > 0) {
                $wallet = $this->wallets->walletFor(OwnerType::User, $user->id);
                $currentBalance = $this->wallets->generalBalance($wallet);

                if ($currentBalance < $pricePoints) {
                    $shortage = $pricePoints - $currentBalance;

                    throw ValidationException::withMessages([
                        'points' => [__('api.event.insufficient_points', [
                            'current' => $currentBalance,
                            'required' => $pricePoints,
                            'shortage' => $shortage,
                        ])],
                    ]);
                }

                $eventTitle = is_array($lockedEvent->title)
                    ? ($lockedEvent->title['en'] ?? $lockedEvent->title['ar'] ?? "Event #{$lockedEvent->id}")
                    : (string) $lockedEvent->title;

                $this->wallets->debitGeneral(
                    $wallet,
                    $pricePoints,
                    "Event registration: {$eventTitle}"
                );

                $pointsDeducted = $pricePoints;
            }

            // 7. Create Attendee record populated from user profile
            $attendee = EventAttendee::create([
                'event_id' => $lockedEvent->id,
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'attended' => null,
            ]);

            $locale = app()->getLocale() ?: 'ar';
            $successMessage = $pointsDeducted > 0
                ? __('api.event.registration_success_points', ['points' => $pointsDeducted])
                : __('api.event.registration_success');

            return [
                'message' => $successMessage,
                'ticket_id' => $attendee->id,
                'event' => [
                    'id' => $lockedEvent->id,
                    'title' => $lockedEvent->translate('title', $locale),
                    'start_at' => $lockedEvent->start_at?->format('Y-m-d'),
                    'location' => $lockedEvent->location,
                    'price_points' => $pricePoints,
                ],
            ];
        });
    }
}