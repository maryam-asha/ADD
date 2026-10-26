<?php

namespace App\Http\Requests\Member;

use App\Domain\Finance\Payments\PaymentMethodRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The member's "end session" press, after the checkout showed them the time
 * and the price: `checkout_token` is that checkout, so the session is closed
 * at exactly what they agreed to, and `payment_method` how they will pay —
 * `wallet` spends their points when reception confirms, `cash` is handed
 * over at the desk, a gateway (`paymera`) is paid in the app once reception
 * has confirmed (docs/decisions/coworking-self-service-sessions.md).
 */
class RequestSessionEndRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'checkout_token' => ['required', 'string'],
            'payment_method' => ['required', 'string', Rule::in(array_keys(app(PaymentMethodRegistry::class)->enabled()))],
        ];
    }
}
