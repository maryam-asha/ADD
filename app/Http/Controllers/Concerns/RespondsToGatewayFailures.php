<?php

namespace App\Http\Controllers\Concerns;

use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Exceptions\PaymeraException;
use App\Domain\Finance\Exceptions\PaymeraUnauthorizedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the member sees when a card payment cannot be opened — one mapping
 * for every endpoint that starts one.
 *
 * A rejected key, an unregistered server IP or a charge that cannot be
 * priced (no exchange rate) is our fault, not the member's, so it must not
 * read as a declined card, and the underlying detail must not reach the
 * client (503). Any other gateway failure is a plain "could not start"
 * (502). Anything else is not a gateway failure and is left to propagate
 * (null).
 */
trait RespondsToGatewayFailures
{
    protected function gatewayFailureResponse(Throwable $e, int $userId): ?JsonResponse
    {
        $failure = $this->gatewayFailure($e, $userId);

        return $failure === null ? null : response()->json(['message' => __($failure['key'])], $failure['status']);
    }

    /**
     * The failure logged, as the message key and status to answer with; null
     * when `$e` is not a gateway failure.
     *
     * @return array{key: string, status: int}|null
     */
    protected function gatewayFailure(Throwable $e, int $userId): ?array
    {
        // The charge could not be priced — a charge currency with no
        // exchange rate. Ours to fix, not the member's: the same answer and
        // alert the global renderer gives (bootstrap/app.php), here so a
        // record already made (a booking, a registration) can still be
        // returned beside it.
        if ($e instanceof PaymentPricingException) {
            Log::channel('paymera')->alert('[Paymera] payment could not be priced', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return ['key' => 'api.payment.gateway_unavailable', 'status' => 503];
        }

        if ($e instanceof PaymeraUnauthorizedException) {
            Log::channel('paymera')->critical('[Paymera] gateway credentials rejected', [
                'user_id' => $userId,
            ]);

            return ['key' => 'api.payment.gateway_unavailable', 'status' => 503];
        }

        if ($e instanceof PaymeraException) {
            Log::channel('paymera')->error('[Paymera] could not open payment', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return ['key' => 'api.payment.could_not_start', 'status' => 502];
        }

        return null;
    }
}
