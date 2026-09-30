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
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\OwnerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\Booking\ExtendBookingRequest;
use App\Http\Requests\Member\Booking\ListBookingsRequest;
use App\Http\Requests\Member\Booking\PreviewBookingRequest;
use App\Http\Requests\Member\Booking\StoreBookingBatchRequest;
use App\Http\Requests\Member\Booking\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\MemberBookingDetailResource;
use App\Http\Resources\MemberBookingResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    use LogsSensitiveActions;

    /**
     * The member's own bookings, with the tab counts the list header shows.
     *
     * Follows EventBrowseService's {counts, data, meta} convention so the
     * bookings tab and the events tab behave identically in the app.
     */
    public function index(ListBookingsRequest $request, BookingPresentationService $presentation): JsonResponse
    {
        $member = $request->user();
        $filter = $request->validated('filter') ?? 'upcoming';
        $perPage = (int) ($request->validated('per_page') ?? 15);

        $base = fn () => Booking::query()->where('user_id', $member->id);

        $paginator = $base()
            // Without this the list runs a query per card for the space,
            // its building, its branch and its floor.
            ->with(['space.building.branch', 'space.zone.floor'])
            ->when($filter === 'upcoming', fn ($query) => $query->where('end_at', '>=', now())->orderBy('start_at'))
            ->when($filter === 'past', fn ($query) => $query->where('end_at', '<', now())->orderByDesc('start_at'))
            ->when($filter === 'all', fn ($query) => $query->orderByDesc('start_at'))
            ->paginate($perPage);

        return response()->json([
            'counts' => [
                'upcoming' => (clone $base())->where('end_at', '>=', now())->count(),
                'past' => (clone $base())->where('end_at', '<', now())->count(),
                'pending' => (clone $base())->where('status', BookingStatus::Pending)->count(),
                'total' => (clone $base())->count(),
            ],
            'data' => MemberBookingResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'filter' => $filter,
            ],
        ]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $this->assertOwned($booking, $request);

        $booking->loadMissing(['space.building.branch', 'space.zone.floor']);

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

        $breakdown = $presentation->breakdown($space, $start, $end, $locale, member: $request->user());

        return response()->json([
            'data' => [
                'space' => [
                    'id' => $space->id,
                    'title' => $space->translate('name', $locale),
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

    public function store(StoreBookingRequest $request, BookingCreationService $creations): JsonResponse
    {
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

        return response()->json(['data' => new BookingResource($booking)], 201);
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

    public function extend(ExtendBookingRequest $request, Booking $booking, BookingExtensionService $extensions): JsonResponse
    {
        $this->assertOwned($booking, $request);

        try {
            $extensions->extend($booking, $request->validated('additional_minutes'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_extended', $booking, ['additional_minutes' => $request->validated('additional_minutes')]);

        return response()->json(['message' => __('api.booking.extended')]);
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
