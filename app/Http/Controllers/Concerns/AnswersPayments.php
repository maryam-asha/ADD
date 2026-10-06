<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Booking\Exceptions\ReceptionActionException;
use App\Domain\Booking\Exceptions\WalletChoiceRequiredException;
use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Payments\CheckoutOutcome;
use App\Domain\Finance\Payments\Exceptions\UnknownPaymentMethodException;
use App\Domain\Finance\Payments\PayableCheckout;
use App\Domain\Identity\Models\User;
use App\Http\Resources\Finance\PaymentResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * How every member endpoint that pays answers — once, for bookings,
 * registrations, coworking sessions and top-ups alike. InsufficientPoints
 * and a refused coupon are left to their global renderers
 * (bootstrap/app.php).
 */
trait AnswersPayments
{
    use RespondsToGatewayFailures;

    /**
     * @return array{payment: PaymentResource|null, redirect_url: string|null, next: string}
     */
    protected function checkoutFields(CheckoutOutcome $outcome): array
    {
        return [
            'payment' => $outcome->payment === null ? null : new PaymentResource($outcome->payment),
            // What the app loads in its WebView, when there is one.
            'redirect_url' => $outcome->start->redirectUrl,
            'next' => $outcome->start->kind,
        ];
    }

    /**
     * The answer for a payment that could not be taken; null when `$e` is
     * not one this maps (the caller rethrows).
     */
    protected function paymentFailureResponse(Throwable $e, int $userId): ?JsonResponse
    {
        if ($e instanceof ReceptionActionException) {
            return response()->json(['message' => __($e->messageKey, $e->params)], $e->status);
        }

        if ($e instanceof WalletChoiceRequiredException) {
            return response()->json([
                'message' => __('api.booking.wallet_choice_required'),
                'wallet_options' => $e->options,
            ], 422);
        }

        if ($e instanceof UnknownPaymentMethodException) {
            // The chosen gateway was switched off in config.
            return response()->json(['message' => __('api.payment.gateway_unavailable')], 503);
        }

        return $this->gatewayFailureResponse($e, $userId);
    }

    /**
     * The payment of a record made in this same request (a booking, a
     * registration). The record stands whatever happens to its payment, so
     * one that could not be opened is answered as `payment_error` beside
     * it — the member can pay again, or at the desk.
     *
     * @return array{payment: PaymentResource|null, redirect_url: string|null, payment_error?: string}
     */
    protected function payBesideNewRecord(PayableCheckout $checkout, PaymentPurpose $purpose, Model $record, User $member, string $method): array
    {
        try {
            $outcome = $checkout->pay($purpose, $record, $member, $method);
        } catch (ReceptionActionException $e) {
            return ['payment' => null, 'redirect_url' => null, 'payment_error' => __($e->messageKey, $e->params)];
        } catch (Throwable $e) {
            $failure = $this->gatewayFailure($e, $member->id) ?? throw $e;

            return ['payment' => null, 'redirect_url' => null, 'payment_error' => __($failure['key'])];
        }

        return [
            'payment' => $outcome->payment === null ? null : new PaymentResource($outcome->payment),
            'redirect_url' => $outcome->start->redirectUrl,
        ];
    }
}
