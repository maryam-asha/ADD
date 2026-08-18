<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Services\BookingCreationService;
use App\Domain\Booking\Services\BookingExtensionService;
use App\Domain\Foundation\Models\Space;
use App\Domain\Membership\Enums\OwnerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\Booking\ExtendBookingRequest;
use App\Http\Requests\Member\Booking\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class BookingController extends Controller
{
    use LogsSensitiveActions;

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
                Carbon::parse($request->validated('start_at')),
                Carbon::parse($request->validated('end_at')),
                $walletOwnerType,
                $request->validated('wallet_owner_id'),
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

    public function extend(ExtendBookingRequest $request, Booking $booking, BookingExtensionService $extensions): JsonResponse
    {
        if (! $booking->user->is($request->user())) {
            abort(403);
        }

        try {
            $extensions->extend($booking, $request->validated('additional_minutes'));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        $this->logSensitiveAction('booking_extended', $booking, ['additional_minutes' => $request->validated('additional_minutes')]);

        return response()->json(['message' => __('api.booking.extended')]);
    }
}
