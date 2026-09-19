<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingApprovalService;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\CheckOutSessionRequest;
use App\Http\Requests\Admin\Reception\ExtendBookingRequest;
use App\Http\Requests\Admin\Reception\RejectBookingRequest;
use App\Http\Requests\Admin\Reception\SettlePaymentRequest;
use App\Http\Requests\Admin\Reception\StoreReceptionBookingRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\PendingApprovalBookingResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
    public function store(StoreReceptionBookingRequest $request, BookingCreationService $creations): JsonResponse
    {
        $space = Space::findOrFail($request->validated('space_id'));
        $member = User::findOrFail($request->validated('user_id'));

        $walletOwnerType = $request->validated('wallet_owner_type')
            ? OwnerType::from($request->validated('wallet_owner_type'))
            : null;

        try {
            $booking = $creations->create(
                $space,
                $member,
                Carbon::parse($request->validated('start_at'), 'UTC'),
                Carbon::parse($request->validated('end_at'), 'UTC'),
                $walletOwnerType,
                $request->validated('wallet_owner_id'),
                $request->validated('note'),
            );
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        } catch (WalletChoiceRequiredException $e) {
            return response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422);
        }

        $this->logSensitiveAction('booking_created_by_reception', $booking, ['member_id' => $member->id]);

        return response()->json(['data' => new BookingResource($booking)], 201);
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

    public function checkIn(Booking $booking, BusinessHoursService $businessHours): JsonResponse
    {
        if ($booking->status === BookingStatus::Cancelled) {
            return response()->json(['message' => __('api.reception.already_cancelled')], 409);
        }

        if ($booking->status === BookingStatus::Pending) {
            return response()->json(['message' => __('api.booking.check_in_requires_approval')], 409);
        }

        if ($booking->status === BookingStatus::Rejected) {
            return response()->json(['message' => __('api.booking.check_in_rejected')], 409);
        }

        if ($booking->checked_in_at !== null) {
            return response()->json(['message' => __('api.reception.already_checked_in')], 409);
        }

        $branch = $booking->space->building->branch;

        if (! $businessHours->isWithinBusinessHours(now(), $branch)) {
            return response()->json(['message' => __('api.reception.outside_business_hours')], 422);
        }

        $booking->forceFill(['checked_in_at' => now()])->save();

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

    public function extend(ExtendBookingRequest $request, Booking $booking, BookingExtensionService $extensions): JsonResponse
    {
        try {
            $extensions->extend($booking, $request->validated('additional_minutes'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_extended', $booking, ['additional_minutes' => $request->validated('additional_minutes')]);

        return response()->json(['message' => __('api.booking.extended')]);
    }
}
