<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use App\Domain\Finance\Services\StartPayment;
use App\Http\Controllers\Concerns\AnswersPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreTopUpRequest;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST member/wallet/top-ups — the member adds points to their own wallet
 * through the gateway they name in `payment_method` (today `paymera`, the
 * card); the app opens `redirect_url` in a WebView and polls
 * GET member/payments/{id}.
 */
class TopUpController extends Controller
{
    use AnswersPayments;

    public function store(StoreTopUpRequest $request, StartPayment $card): JsonResponse
    {
        $member = $request->user();

        try {
            ['payment' => $payment, 'url' => $url] = $card->handle(
                user: $member,
                purpose: PaymentPurpose::WalletTopUp,
                chargedAmount: $request->chargedAmount(),
                lang: app()->getLocale(),
                method: $request->validated('payment_method'),
            );
        } catch (UnknownPaymentMethodException) {
            return response()->json(['message' => __('api.payment.gateway_unavailable')], 503);
        } catch (Throwable $e) {
            return $this->gatewayFailureResponse($e, $member->id) ?? throw $e;
        }

        return response()->json([
            'data' => [
                'payment' => new PaymentResource($payment),
                'redirect_url' => $url,
                'next' => 'redirect',
            ],
        ], 201);
    }
}
