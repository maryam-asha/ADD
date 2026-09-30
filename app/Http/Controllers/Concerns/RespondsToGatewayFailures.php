<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Exceptions\PaymeraUnauthorizedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the member sees when a card payment cannot be opened — one mapping
 * for every endpoint that starts one.
 *
 * A rejected key or an unregistered server IP is our fault, not the
 * member's, so it must not read as a declined card, and the underlying
 * detail must not reach the client (503). Any other gateway failure is a
 * plain "could not start" (502). Anything else is not a gateway failure and
 * is left to propagate (null).
 */
trait RespondsToGatewayFailures
{
    protected function gatewayFailureResponse(Throwable $e, int $userId): ?JsonResponse
    {
        if ($e instanceof PaymeraUnauthorizedException) {
            Log::channel('paymera')->critical('[Paymera] gateway credentials rejected', [
                'user_id' => $userId,
            ]);

            return response()->json(['message' => __('api.payment.gateway_unavailable')], 503);
        }

        if ($e instanceof PaymeraException) {
            Log::channel('paymera')->error('[Paymera] could not open payment', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => __('api.payment.could_not_start')], 502);
        }

        return null;
    }
}
