<?php

namespace App\Services\Experience;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Services\EventLocationService;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Exceptions\InsufficientPointsException;
use App\Domain\Finance\Payments\Methods\CashMethod;
use App\Domain\Finance\Payments\Methods\WalletMethod;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Payments\PrepaymentHold;
use App\Domain\Forms\Services\FormSubmissionService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Pricing\Enums\PricingPurpose;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Domain\Pricing\Services\QuoteRecorder;
use App\Services\Notification\NotificationService;
use App\Support\Enums\EventPaymentState;
use App\Support\Enums\EventRegistrationStatus;
use App\Support\Time\LocalTime;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EventRegistrationService
{
    use LogsSensitiveActions;

    public function __construct(
        private readonly FormSubmissionService $forms,
        private readonly PaymentService $payments,
        private readonly PriceQuoteService $quotes,
        private readonly QuoteRecorder $recorder,
        private readonly CouponEligibility $coupons,
        private readonly CouponLedger $couponLedger,
        private readonly PaymentMethodRegistry $methods,
        private readonly PrepaymentHold $holds,
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
    public function register(
        Event $event,
        User $user,
        array $fieldValues = [],
        ?string $paymentMethod = null,
        ?OwnerType $walletOwnerType = null,
        ?int $walletOwnerId = null,
        ?string $couponCode = null,
    ): array {
        // An unknown code is refused before anything is locked.
        $coupon = $couponCode !== null ? $this->coupons->resolve($couponCode) : null;

        return DB::transaction(function () use ($event, $user, $fieldValues, $paymentMethod, $walletOwnerType, $walletOwnerId, $coupon) {
            // 0. The coupon's row before the event's (CouponLedger).
            $coupon = $coupon !== null ? $this->couponLedger->lockForRedemption($coupon) : null;

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

            // 3. Validate the registration window. It closes at the event's
            // start unless the admin closed it earlier — an event under way
            // takes no one new, however long it still runs.
            $window = $lockedEvent->registrationWindow();

            if ($window === 'closed') {
                throw ValidationException::withMessages([
                    'event' => [__('api.event.closed')],
                ]);
            }

            if ($window === 'not_yet_open') {
                throw ValidationException::withMessages([
                    'event' => [__('api.event.not_yet_open', [
                        'date' => app(LocalTime::class)->toLocal($lockedEvent->registration_opens_at)->format('Y-m-d H:i'),
                    ])],
                ]);
            }

            if ($lockedEvent->is_marked_full) {
                throw ValidationException::withMessages([
                    'capacity' => [__('api.event.full')],
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

            // 6. Payment. A paid event's price is recorded on the
            // registration, with how the member means to pay. Points are
            // only ever taken from an approved registration, so a pending
            // one costs nothing and a rejection needs no refund; an approved
            // one paid by cash or card is confirmed unpaid and cannot be
            // checked in until it is paid.
            $isFree = (bool) ($lockedEvent->is_free ?? true);
            $listPoints = (int) ($lockedEvent->price_points ?? 0);
            $isPaidEvent = ! $isFree && $listPoints > 0;

            // Priced by PriceQuoteService — a coupon on top of the price. A
            // registration a coupon makes free is paid: the door admits only
            // a paid one.
            $quote = $this->quotes->forEvent($lockedEvent, $user, $coupon);
            $pricePoints = $quote->finalPoints;
            $madeFree = $isPaidEvent && $pricePoints === 0;
            $method = $paymentMethod ?? WalletMethod::KEY;
            $pointsDeducted = 0;

            // A seat confirmed on the spot and paid by a prepaid method is
            // held until the card goes through or another method is chosen
            // (docs/decisions/prepayment-all-or-nothing.md).
            $held = $status === EventRegistrationStatus::Approved
                && $isPaidEvent && ! $madeFree
                && in_array($method, $this->methods->gatewayKeys(), true);

            if ($held) {
                $status = EventRegistrationStatus::AwaitingPayment;
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
                'payment_due_at' => $held ? $this->holds->dueAt($lockedEvent->start_at) : null,
                'attended' => null,
                'price_points' => $isPaidEvent ? $pricePoints : null,
                'list_price_points' => $isPaidEvent ? $listPoints : null,
                'payment_state' => $isPaidEvent ? ($madeFree ? EventPaymentState::Paid : EventPaymentState::Unpaid) : null,
                'paid_at' => $madeFree ? now() : null,
                'payment_method' => $isPaidEvent ? $method : null,
                'payment_wallet_owner_type' => $isPaidEvent ? $walletOwnerType?->value : null,
                'payment_wallet_owner_id' => $isPaidEvent ? $walletOwnerId : null,
            ]);

            $this->recorder->record($quote, $attendee, $user, PricingPurpose::EventRegistration);

            if ($status === EventRegistrationStatus::Approved && $isPaidEvent && ! $madeFree && $method === WalletMethod::KEY) {
                $pointsDeducted = $this->payFromWallet($attendee, $user, $pricePoints, $paymentMethod === null, $walletOwnerType, $walletOwnerId);
            }

            // Cash confirms the seat unpaid: the member is told they owe it.
            // A card is being paid right now, so it is not.
            if ($status === EventRegistrationStatus::Approved && ! $madeFree && $method === CashMethod::KEY) {
                $this->tellPaymentDue($attendee, $lockedEvent);
            }

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

            $this->logSensitiveAction('event_registered', $attendee, [
                'price_points' => $isPaidEvent ? $pricePoints : 0,
                'list_price_points' => $isPaidEvent ? $listPoints : 0,
                'payment_method' => $isPaidEvent ? $method : null,
            ]);

            return [
                'message' => $successMessage,
                'ticket_id' => $attendee->id,
                'status' => $status->value,
                'requires_approval' => (bool) $lockedEvent->requires_approval,
                'payment_due_at' => $attendee->payment_due_at?->toIso8601String(),
                'event' => [
                    'id' => $lockedEvent->id,
                    'title' => $lockedEvent->translate('title', $locale),
                    'start_at' => $lockedEvent->start_at?->format('Y-m-d'),
                    'location' => app(EventLocationService::class)->displayLocation($lockedEvent, $locale),
                    // The event's own price; what this registration costs is
                    // the attendee's.
                    'price_points' => $listPoints,
                ],
            ];
        });
    }

    /**
     * Pays an approved registration from the wallet, now, through the one
     * payment path — a payment row naming the registration and the wallet.
     *
     * An app that sends no method is paying the way it always did: from the
     * member's own wallet, never asked to choose. One that says `wallet`
     * chooses like a booking does (a company wallet among them).
     *
     * A wallet that falls short keeps the error this endpoint has always
     * given — the `points` validation message with the numbers.
     *
     * @return int the points taken
     *
     * @throws ValidationException
     */
    private function payFromWallet(
        EventAttendee $attendee,
        User $user,
        int $pricePoints,
        bool $asBefore,
        ?OwnerType $walletOwnerType,
        ?int $walletOwnerId,
    ): int {
        // TEMPORARY (config/events.php → EVENT_SKIP_BALANCE_CHECK): skips the
        // charge outright for testing, so a member registers for a paid event
        // without being charged and without a balance check. The seat counts
        // as paid, so the door lets them in. Must be false in production —
        // see the config note.
        if ((bool) config('events.skip_balance_check', false)) {
            $attendee->forceFill(['payment_state' => EventPaymentState::Paid, 'paid_at' => now()])->save();

            return 0;
        }

        if ($asBefore) {
            $walletOwnerType = OwnerType::User;
            $walletOwnerId = $user->id;
        }

        try {
            $this->payments->start(PaymentPurpose::EventRegistration, $user, WalletMethod::KEY, [
                'payable' => $attendee,
                'wallet_owner_type' => $walletOwnerType,
                'wallet_owner_id' => $walletOwnerId,
            ]);
        } catch (InsufficientPointsException $e) {
            throw ValidationException::withMessages([
                'points' => [__('api.event.insufficient_points', [
                    'current' => $e->current,
                    'required' => $e->required,
                    'shortage' => $e->shortage,
                ])],
            ]);
        } catch (ReceptionActionException $e) {
            throw ValidationException::withMessages(['wallet' => [__($e->messageKey, $e->params)]]);
        } catch (WalletChoiceRequiredException $e) {
            throw new HttpResponseException(response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422));
        }

        $attendee->refresh();

        return $pricePoints;
    }

    /**
     * A seat confirmed for a paid event but not paid for yet (cash at the
     * desk, or a card still to be paid): the member is told they owe it,
     * after commit.
     */
    private function tellPaymentDue(EventAttendee $attendee, Event $event): void
    {
        if ($attendee->payment_state !== EventPaymentState::Unpaid || $attendee->user_id === null) {
            return;
        }

        DB::afterCommit(function () use ($attendee, $event) {
            $member = User::query()->find($attendee->user_id);

            if ($member === null) {
                return;
            }

            app(NotificationService::class)->sendToUser($member, 'event.payment_due', [
                'event' => $event->translate('title', $member->preferred_language ?? config('app.locale')),
                'points' => (int) $attendee->price_points,
            ], [
                'purpose' => 'event_registration',
                'payable_type' => $attendee->getMorphClass(),
                'payable_id' => $attendee->id,
                // Paying is `events/{event_id}/register` with the ticket.
                'event_id' => $attendee->event_id,
            ]);
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
