<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCancellationService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Services\BusinessHoursService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\CheckOutSessionRequest;
use App\Http\Requests\Admin\Reception\SettlePaymentRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class BookingReceptionController extends Controller
{
    use LogsSensitiveActions;

    public function checkIn(Booking $booking, BusinessHoursService $businessHours): JsonResponse
    {
        if ($booking->status === BookingStatus::Cancelled) {
            return response()->json(['message' => __('api.reception.already_cancelled')], 409);
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
            $closures->closeOut($booking, Carbon::parse($request->validated('checked_out_at')));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('booking_checked_out', $booking, ['amount_owed' => (string) $booking->amount_owed]);

        return response()->json(['message' => __('api.reception.checked_out')]);
    }

    public function cancel(Booking $booking, BookingCancellationService $cancellations): JsonResponse
    {
        try {
            $cancellations->cancel($booking);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('booking_cancelled', $booking);

        return response()->json(['message' => __('api.reception.cancelled')]);
    }

    public function settlePayment(SettlePaymentRequest $request, Booking $booking, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->settlePayment($booking, PaymentMethod::from($request->validated('payment_method')), $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('payment_settled', $booking, ['payment_method' => $booking->payment_method->value]);

        return response()->json(['message' => __('api.reception.payment_settled')]);
    }
}
