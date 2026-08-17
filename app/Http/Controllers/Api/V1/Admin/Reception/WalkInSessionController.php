<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Booking\Services\WalkInCapacityService;
use App\Domain\Finance\Enums\PaymentMethod;
use App\Domain\Foundation\Models\Space;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reception\CheckOutSessionRequest;
use App\Http\Requests\Admin\Reception\SettlePaymentRequest;
use App\Http\Requests\Admin\Reception\StoreWalkInSessionRequest;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class WalkInSessionController extends Controller
{
    use LogsSensitiveActions;

    public function store(StoreWalkInSessionRequest $request, WalkInCapacityService $capacity): JsonResponse
    {
        $space = Space::findOrFail($request->validated('space_id'));
        $member = User::findOrFail($request->validated('user_id'));

        try {
            $session = $capacity->start($space, $member);
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('walkin_session_started', $session);

        return response()->json([
            'data' => [
                'id' => $session->id,
                'space_id' => $session->space_id,
                'user_id' => $session->user_id,
                'checked_in_at' => $session->checked_in_at->toIso8601String(),
            ],
        ], 201);
    }

    public function checkOut(CheckOutSessionRequest $request, WalkinSession $walkinSession, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->closeOut($walkinSession, Carbon::parse($request->validated('checked_out_at')));
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('walkin_session_checked_out', $walkinSession, ['amount_owed' => (string) $walkinSession->amount_owed]);

        return response()->json(['message' => __('api.reception.checked_out')]);
    }

    public function settlePayment(SettlePaymentRequest $request, WalkinSession $walkinSession, SessionClosureService $closures): JsonResponse
    {
        try {
            $closures->settlePayment($walkinSession, PaymentMethod::from($request->validated('payment_method')), $request->user());
        } catch (ReceptionActionException $e) {
            return response()->json(['message' => __($e->messageKey)], $e->status);
        }

        $this->logSensitiveAction('payment_settled', $walkinSession, ['payment_method' => $walkinSession->payment_method->value]);

        return response()->json(['message' => __('api.reception.payment_settled')]);
    }
}
