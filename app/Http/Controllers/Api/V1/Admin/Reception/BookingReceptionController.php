<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCheckInService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Booking\Services\BookingPresentationService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Payments\PaymentService;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\CheckOutSessionRequest;
use App\Http\Requests\Admin\Reception\ExtendBookingRequest;
use App\Http\Requests\Admin\Reception\QuoteReceptionBookingRequest;
use App\Http\Requests\Admin\Reception\RejectBookingRequest;
use App\Http\Requests\Admin\Reception\SettlePaymentRequest;
use App\Http\Requests\Admin\Reception\StoreReceptionBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\PendingApprovalBookingResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BookingReceptionController extends Controller
{
    use LogsSensitiveActions;

    /**
     * Reception creating a booking on a member's behalf — the walk-up at
     * the desk, and the "book it for them" action on the calendar.
     *
     * Reuses BookingCreationService unchanged, so every rule the member
     * endpoint enforces applies here too: business hours, granularity,
     * duration, overlap, buffer and the space's own ceiling. The only
     * difference is that the member comes from the body rather than the
     * token.
     */
    public function store(StoreReceptionBookingRequest $request, BookingCreationService $creations, PaymentService $payments): JsonResponse
    {
        $space = Space::findOrFail($request->validated('space_id'));
        $member = User::findOrFail($request->validated('user_id'));

        $walletOwnerType = $request->validated('wallet_owner_type')
            ? OwnerType::from($request->validated('wallet_owner_type'))
            : null;

        $method = $request->validated('payment_method');
        $collected = $method === 'cash' && $request->boolean('cash_collected');

        try {
            // One transaction: a booking whose cash could not be recorded is
            // not left behind unpaid.
            $booking = DB::transaction(function () use ($creations, $payments, $request, $space, $member, $walletOwnerType, $method, $collected) {
                $booking = $creations->create(
                    $space,
                    $member,
                    Carbon::parse($request->validated('start_at'), 'UTC'),
                    Carbon::parse($request->validated('end_at'), 'UTC'),
                    $walletOwnerType,
                    $request->validated('wallet_owner_id'),
                    $request->validated('note'),
                    $method,
                );

                if ($collected && (int) $booking->price_points > 0) {
                    $payments->settleAtReception(PaymentPurpose::Booking, $booking, PaymentMethod::Cash, null, null, $request->user());
                }

                return $booking->fresh();
            });
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        } catch (PaymentPricingException) {
            return response()->json(['message' => __('api.payment.currency_unavailable')], 422);
        } catch (WalletChoiceRequiredException $e) {
            return response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422);
        }

        $this->logSensitiveAction('booking_created_by_reception', $booking, [
            'member_id' => $member->id,
            'payment_method' => $method,
            'cash_collected' => $collected,
        ]);

        return response()->json(['data' => new BookingResource($booking)], 201);
    }

    /**
     * What the booking would cost the member, so the desk knows what to ask
     * for before choosing how it is paid: hours × the space's hourly rate,
     * less the member's plan discount — the same breakdown the member app
     * previews (BookingPresentationService::breakdown) — the total in every
     * currency the desk takes cash in, and the member's wallets with whether
     * each covers it.
     */
    public function quote(QuoteReceptionBookingRequest $request, BookingPresentationService $presentation, ReceivedMoneyConverter $converter): JsonResponse
    {
        $space = Space::findOrFail($request->validated('space_id'));
        $member = User::findOrFail($request->validated('user_id'));
        $locale = app()->getLocale();

        $start = Carbon::parse($request->validated('start_at'), 'UTC');
        $end = Carbon::parse($request->validated('end_at'), 'UTC');

        $breakdown = $presentation->breakdown($space, $start, $end, $locale, member: $member);
        $points = $breakdown['total']['points'];

        // A currency with no exchange rate yet is left out rather than
        // failing the whole quote.
        $amounts = Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('order')->pluck('code')
            ->map(function (string $code) use ($converter, $points) {
                try {
                    return ['currency' => $code, 'value' => $converter->pointsToAmount($points, $code)];
                } catch (PaymentPricingException) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();

        return response()->json(['data' => [
            'minutes' => (int) $start->diffInMinutes($end),
            'hourly_rate_points' => (int) ($space->hourly_rate_points ?? 0),
            'breakdown' => $breakdown,
            'total_points' => $points,
            'amounts' => $amounts,
            'wallets' => collect($presentation->paymentMethods($member, $points, $locale))
                ->where('key', 'wallet')
                ->map(fn (array $wallet) => [
                    'owner_type' => $wallet['owner_type'],
                    'owner_id' => $wallet['owner_id'],
                    'owner_label' => $wallet['owner_label'],
                    'usable_balance' => $wallet['usable_balance'],
                    'sufficient' => $wallet['sufficient'],
                ])
                ->values()
                ->all(),
        ]]);
    }

    public function pendingApproval(): AnonymousResourceCollection
    {
        $bookings = Booking::query()
            ->where('status', BookingStatus::Pending)
            ->orderByDesc('start_at')
            ->with('space.building.branch', 'user')
            ->paginate(25);

        return PendingApprovalBookingResource::collection($bookings);
    }

    public function checkIn(Booking $booking, BookingCheckInService $checkIns): JsonResponse
    {
        try {
            $checkIns->checkIn($booking);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_checked_in', $booking);

        return response()->json(['message' => __('api.reception.checked_in')]);
    }

    public function checkOut(CheckOutSessionRequest $request, Booking $booking, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->closeOut($booking, Carbon::parse($request->validated('checked_out_at'), 'UTC'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_checked_out', $booking, ['amount_owed' => (string) $booking->amount_owed]);

        return response()->json(['message' => __('api.reception.checked_out')]);
    }

    public function cancel(Booking $booking, BookingCancellationService $cancellations): JsonResponse
    {
        try {
            $cancellations->cancel($booking);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_cancelled', $booking);

        return response()->json(['message' => __('api.reception.cancelled')]);
    }

    public function settlePayment(SettlePaymentRequest $request, Booking $booking, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->settlePayment($booking, PaymentMethod::from($request->validated('payment_method')), $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('payment_settled', $booking, ['payment_method' => $booking->payment_method->value]);

        return response()->json(['message' => __('api.reception.payment_settled')]);
    }

    public function approve(Request $request, Booking $booking, BookingApprovalService $approvals): JsonResponse
    {
        try {
            $approvals->approve($booking, $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_approved', $booking);

        return response()->json(['message' => __('api.booking.approved')]);
    }

    public function reject(RejectBookingRequest $request, Booking $booking, BookingApprovalService $approvals): JsonResponse
    {
        $reason = $request->validated('rejection_reason');

        try {
            $approvals->reject($booking, $request->user(), $reason);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_rejected', $booking, ['rejection_reason' => $reason]);

        return response()->json(['message' => __('api.booking.rejected')]);
    }

    /**
     * The same extension options the member sees, for the desk extending on
     * their behalf (docs/decisions/booking-outcomes-and-extension.md);
     * prices in the member's own currency.
     */
    public function extensionOptions(Booking $booking, BookingPresentationService $presentation): JsonResponse
    {
        $booking->loadMissing('space.building.branch', 'user');

        return response()->json([
            'data' => $presentation->extensionOptions($booking, app()->getLocale(), $booking->user),
        ]);
    }

    public function extend(ExtendBookingRequest $request, Booking $booking, BookingExtensionService $extensions): JsonResponse
    {
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
}
