<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\BatchUnavailableException;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\AvailabilityService;
use App\Domain\Booking\Services\BookingBatchCreationService;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Domain\Booking\ValueObjects\PoolDemand;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Pricing\Services\CouponEligibility;
use App\Domain\Pricing\Services\CouponLedger;
use App\Domain\Pricing\Services\PriceQuoteService;
use App\Http\Controllers\Concerns\RespondsToGatewayFailures;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\Booking\ExtendBookingRequest;
use App\Http\Requests\Member\Booking\ListBookingsRequest;
use App\Http\Requests\Member\Booking\PreviewBookingRequest;
use App\Http\Requests\Member\Booking\StoreBookingBatchRequest;
use App\Http\Requests\Member\Booking\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\Finance\PaymentResource;
use App\Http\Resources\MemberBookingDetailResource;
use App\Http\Resources\MemberBookingResource;
use App\Support\Presenters\CouponOutcomePresenter;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BookingController extends Controller
{
    use LogsSensitiveActions;
    use RespondsToGatewayFailures;

    /**
     * The member's own bookings, filtered on their `status` — one or several,
     * none for all — as a page and its `meta`
     * (docs/decisions/booking-outcomes-and-extension.md).
     *
     * What has not started comes first, soonest first, then everything else,
     * latest first: the design's "Upcoming" above the rest, whatever is asked
     * for. No per-tab `counts`: the screen shows none, and each cost a query.
     */
    public function index(ListBookingsRequest $request): JsonResponse
    {
        $statuses = $request->statuses();
        $perPage = (int) ($request->validated('per_page') ?? 15);
        $now = now();

        $paginator = Booking::query()
            ->where('user_id', $request->user()->id)
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->orderByRaw('CASE WHEN start_at > ? THEN 0 ELSE 1 END', [$now])
            ->orderByRaw('CASE WHEN start_at > ? THEN start_at END ASC', [$now])
            ->orderByDesc('start_at')
            // The card reads its space and nothing else; without this, that
            // is a query per card.
            ->with('space')
            ->paginate($perPage);

        return response()->json([
            'data' => MemberBookingResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                // What was filtered on; empty for every booking.
                'status' => array_map(fn (BookingStatus $status) => $status->value, $statuses),
            ],
        ]);
    }

    /**
     * Every booking status with its label in the requested language — what
     * the app draws badges from and offers as `status` filters
     * (docs/decisions/booking-outcomes-and-extension.md).
     */
    public function statuses(): JsonResponse
    {
        $locale = app()->getLocale();

        return response()->json([
            'data' => [
                'statuses' => array_map(fn (BookingStatus $status) => [
                    'key' => $status->value,
                    'label' => $status->label($locale),
                ], BookingStatus::cases()),
            ],
        ]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($booking, $request);

        $booking->loadMissing(['space.building.branch', 'space.zone.floor', 'seatSpace.building', 'seatSpace.zone.floor']);

        return response()->json([
            'data' => new MemberBookingDetailResource($booking),
        ]);
    }

    /**
     * Cancellation is the same service reception calls; the only member-
     * specific part is proving the booking is theirs, which matches the
     * existing extend() precedent rather than introducing a Policy.
     */
    public function cancel(Request $request, Booking $booking, BookingCancellationService $cancellations): JsonResponse
    {
        $this->assertOwned($booking, $request);

        try {
            $cancellations->cancel($booking);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_cancelled', $booking);

        return response()->json(['message' => __('api.booking.cancelled')]);
    }

    /**
     * The confirmation screen, without creating anything.
     *
     * Availability is reported rather than enforced here: this is a
     * read, and the slot can still be taken between previewing and
     * confirming, which is why store() re-checks under a row lock.
     */
    public function preview(
        PreviewBookingRequest $request,
        AvailabilityService $availability,
        BookingPresentationService $presentation,
        PriceQuoteService $quotes,
        CouponEligibility $coupons,
    ): JsonResponse {
        $space = Space::with(['building.branch', 'zone.floor'])->findOrFail($request->validated('space_id'));
        $locale = app()->getLocale();

        $start = Carbon::parse($request->validated('start_at'), 'UTC');
        $end = Carbon::parse($request->validated('end_at'), 'UTC');

        $rules = $availability->rulesFor($space);
        $existing = $availability->blockingBookings($space, $start, $end, $rules);

        $unavailableKey = match (true) {
            ! $availability->acceptsBookings($space) => 'api.booking.space_not_bookable',
            ! $availability->isFarEnoughAhead($start, $rules) => 'api.booking.too_soon',
            ! $availability->isWithinAdvanceHorizon($start, $rules) => 'api.booking.too_far_ahead',
            ! $availability->isWithinBusinessHours($space, $start, $end, $rules) => 'api.reception.outside_business_hours',
            ! $availability->isValidGranularity($start, $rules) => 'api.booking.invalid_start_time',
            ! $availability->isLongEnough($start, $end, $rules) => 'api.booking.duration_too_short',
            ! $availability->matchesDurationGranularity($start, $end, $rules) => 'api.booking.duration_invalid_granularity',
            // A co_space's seat comes from its branch's pool, counted on the
            // member's subscription as the POST counts it; a room may be lent
            // to co-working (docs/decisions/coworking-seat-pool.md).
            $rules->usesBranchPool && $availability->exceedsPool(
                $availability->poolTimeline($space, $start, $end, PoolDemand::presence($start, $end, $request->user())),
                $start,
                $end,
            ) => 'api.booking.slot_unavailable',
            ! $rules->usesBranchPool && $availability->exceedsCeiling($existing, $start, $end, $rules) => 'api.booking.slot_unavailable',
            ! $rules->usesBranchPool && $availability->isInCoworkingMode(
                $availability->blockingCoworkingWindows($space, $start, $end),
                $start,
                $end,
            ) => 'api.booking.room_in_coworking_mode',
            $availability->violatesBuffer($existing, $start, $end, $rules) => 'api.booking.buffer_conflict',
            default => null,
        };

        // Priced exactly as POST bookings will price it — a coupon included —
        // so the preview and the charge cannot disagree.
        $code = $request->validated('coupon_code');

        try {
            $coupon = $code !== null ? $coupons->resolve($code) : null;
            $quote = $quotes->forBooking($space, $start, $end, $request->user(), $coupon);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $breakdown = $presentation->breakdownOf($quote, $space, $start, $end, $locale);

        return response()->json([
            'data' => [
                ...($code !== null ? ['coupon' => app(CouponOutcomePresenter::class)->fromQuote($code, $quote, $locale)] : []),
                'space' => [
                    'id' => $space->id,
                    'title' => $space->displayTitle($locale),
                    'space_type' => $space->space_type->value,
                    'capacity' => $space->capacity,
                    'main_image' => $space->main_image,
                ],
                'when' => $presentation->when($start, $end),
                'breakdown' => $breakdown,
                'payment_methods' => $presentation->paymentMethods(
                    $request->user(),
                    $breakdown['total']['points'],
                    $locale,
                ),
                'requires_approval' => $rules->requiresApproval,
                'approval_notice' => $rules->requiresApproval
                    ? __('api.space.requires_approval_notice', [], $locale)
                    : null,
                'note_field' => [
                    'key' => 'note',
                    'label' => __('api.booking.note_label', [], $locale),
                    'placeholder' => __('api.booking.note_placeholder', [], $locale),
                    'max_length' => 500,
                    'required' => false,
                ],
                'is_available' => $unavailableKey === null,
                'unavailable_reason' => $unavailableKey === null ? null : __($unavailableKey, [], $locale),
            ],
        ]);
    }

    public function store(
        StoreBookingRequest $request,
        BookingCreationService $creations,
        PaymentService $payments,
        PaymentMethodRegistry $methods,
    ): JsonResponse {
        $space = Space::findOrFail($request->validated('space_id'));

        $walletOwnerType = $request->validated('wallet_owner_type')
            ? OwnerType::from($request->validated('wallet_owner_type'))
            : null;

        try {
            $booking = $creations->create(
                $space,
                $request->user(),
                Carbon::parse($request->validated('start_at'), 'UTC'),
                Carbon::parse($request->validated('end_at'), 'UTC'),
                $walletOwnerType,
                $request->validated('wallet_owner_id'),
                $request->validated('note'),
                $request->validated('payment_method'),
                $request->validated('coupon_code'),
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        } catch (WalletChoiceRequiredException $e) {
            return response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422);
        }

        $this->logSensitiveAction('booking_created', $booking);

        $method = $request->validated('payment_method');
        $card = $method !== null && in_array($method, $methods->gatewayKeys(), true)
            ? $this->startCardPayment($payments, $booking, $request->user(), $method, app()->getLocale())
            : [];

        $code = $request->validated('coupon_code');
        $coupon = $code !== null
            ? ['coupon' => app(CouponOutcomePresenter::class)->present($code, app(CouponLedger::class)->couponFor($booking))]
            : [];

        return response()->json(['data' => new BookingResource($booking)] + $card + $coupon, 201);
    }

    /**
     * A booking paid by card is made first, unpaid — it holds its slot like
     * any unpaid booking — and then its payment is opened. If the gateway
     * cannot be reached (or the charge cannot be priced) the booking stands,
     * still unpaid, and the member is told the payment did not start: they
     * can pay again, or at the desk. A booking that costs nothing has no
     * payment to open, and says so the same way.
     *
     * @return array{payment: PaymentResource|null, redirect_url: string|null, payment_error?: string}
     */
    private function startCardPayment(PaymentService $payments, Booking $booking, User $member, string $method, ?string $lang): array
    {
        try {
            ['payment' => $payment, 'start' => $start] = $payments->start(PaymentPurpose::Booking, $member, $method, array_filter([
                'payable' => $booking,
                'lang' => in_array($lang, ['ar', 'en'], true) ? $lang : null,
            ]));
        } catch (ReceptionActionException $e) {
            return ['payment' => null, 'redirect_url' => null, 'payment_error' => __($e->messageKey, $e->params)];
        } catch (Throwable $e) {
            $failure = $this->gatewayFailure($e, $member->id) ?? throw $e;

            return ['payment' => null, 'redirect_url' => null, 'payment_error' => __($failure['key'])];
        }

        return ['payment' => new PaymentResource($payment), 'redirect_url' => $start->redirectUrl];
    }

    /**
     * Several dates, one request, all or nothing.
     *
     * A failure names each window that could not be taken by its index in
     * the submitted list, so the app can mark the offending chips rather
     * than saying "something went wrong".
     */
    public function storeBatch(StoreBookingBatchRequest $request, BookingBatchCreationService $batches): JsonResponse
    {
        $walletOwnerType = $request->validated('wallet_owner_type')
            ? OwnerType::from($request->validated('wallet_owner_type'))
            : null;

        try {
            $bookings = $batches->createMany(
                $request->validated('windows'),
                $request->user(),
                $walletOwnerType,
                $request->validated('wallet_owner_id'),
                $request->validated('note'),
                $request->validated('payment_method'),
                $request->validated('coupon_code'),
            );
        } catch (BatchUnavailableException $e) {
            return response()->json([
                'message' => __('api.booking.batch_partially_unavailable'),
                'errors' => array_map(fn (array $failure) => [
                    'index' => $failure['index'],
                    'start_at' => $failure['start_at'],
                    'reason' => $failure['reason'],
                    'message' => __($failure['message_key']),
                ], $e->failures),
            ], 422);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        } catch (WalletChoiceRequiredException $e) {
            return response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422);
        }

        foreach ($bookings as $booking) {
            $this->logSensitiveAction('booking_created', $booking);
        }

        return response()->json([
            'message' => __('api.booking.batch_created', ['count' => count($bookings)]),
            'data' => BookingResource::collection($bookings)->resolve(),
        ], 201);
    }

    /**
     * How far the member's running booking can be extended and at what price
     * — or why it cannot be (docs/decisions/booking-outcomes-and-extension.md).
     * A read: extend() re-checks the same rules under its locks.
     */
    public function extensionOptions(Request $request, Booking $booking, BookingPresentationService $presentation): JsonResponse
    {
        $this->assertOwned($booking, $request);

        $booking->loadMissing('space.building.branch');

        return response()->json([
            'data' => $presentation->extensionOptions($booking, app()->getLocale(), $request->user()),
        ]);
    }

    public function extend(ExtendBookingRequest $request, Booking $booking, BookingExtensionService $extensions): JsonResponse
    {
        $this->assertOwned($booking, $request);

        try {
            $movedTo = $extensions->extend($booking, $request->validated('additional_minutes'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_extended', $booking, ['additional_minutes' => $request->validated('additional_minutes')]);

        // The room the member sat in goes back to being a room: they are
        // told where to move, the one time it matters.
        return response()->json(['message' => $movedTo === null
            ? __('api.booking.extended')
            : __('api.booking.extended_seat_moved', ['place' => app(BookingPresentationService::class)->seat($movedTo, app()->getLocale())['formatted']])]);
    }

    /**
     * A member may only ever act on their own booking.
     *
     * 403, matching the contract extend() has already shipped
     * (BookingControllerTest::test_a_member_cannot_extend_another_members_booking).
     * A 404 would leak less about the id space, but changing a live
     * response code for that is a separate decision, not a side effect of
     * adding read endpoints.
     */
    private function assertOwned(Booking $booking, Request $request): void
    {
        if ($booking->user_id !== $request->user()->id) {
            abort(403, __('api.booking.not_your_booking'));
        }
    }
}
